<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Error;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Interfaces\ErrorHandlerInterface;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;
use SmokeDetector\Application\TemporarilyUnavailable;
use SmokeDetector\Application\Validation\ValidationFailed;
use SmokeDetector\Http\JsonResponder;
use SmokeDetector\Http\Problem\Problem;
use SmokeDetector\Http\Problem\ProblemException;
use SmokeDetector\Infrastructure\Database\DatabaseConnectionFailed;
use Throwable;

/**
 * Turns everything that is thrown while a request is handled into RFC 9457 problem details.
 *
 * - ProblemException: the problem it carries.
 * - ValidationFailed: 422 validation_failed with the field errors.
 * - RateLimitExceeded: 429 rate_limited with Retry-After.
 * - TemporarilyUnavailable: 503 service_unavailable with Retry-After. The database gave the work
 *   up for now (deadlock, lock wait timeout) and rolled it back. Logged, like every 5xx.
 * - DatabaseConnectionFailed: 503 service_unavailable with a fixed text and nothing about the
 *   server. An outage, not a bug: the client repeats the request later. Logged, like every 5xx.
 * - AccountNotFound: 401 unauthorized. The account was deleted after the request was let in, so
 *   its token is worth nothing any more.
 * - The framework's "no such route" and "wrong method": 404 and 405.
 * - Anything else is a bug or an outage: 500 internal_error, logged, and described in the response
 *   only outside production.
 */
final readonly class ErrorHandler implements ErrorHandlerInterface
{
    private const int MAX_TRACE_FRAMES = 30;

    public function __construct(
        private JsonResponder $responder,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        $problem = $this->problemFor($exception, $displayErrorDetails);

        // What the client did wrong is the client's business. What we did wrong goes into the log.
        if ($problem->status >= 500) {
            $this->log($request, $exception);
        }

        return $this->responder->problem($problem);
    }

    private function problemFor(Throwable $exception, bool $displayErrorDetails): Problem
    {
        if ($exception instanceof ProblemException) {
            return $exception->problem;
        }
        if ($exception instanceof ValidationFailed) {
            return Problem::validationFailed($exception->errors);
        }
        if ($exception instanceof RateLimitExceeded) {
            return Problem::rateLimited($exception->retryAfterSeconds);
        }
        if ($exception instanceof TemporarilyUnavailable) {
            return Problem::serviceUnavailable('The service is busy. Try again in a moment.', $exception->retryAfterSeconds);
        }
        if ($exception instanceof DatabaseConnectionFailed) {
            return Problem::serviceUnavailable('The database cannot be reached.');
        }
        if ($exception instanceof AccountNotFound) {
            return Problem::unauthorized();
        }
        if ($exception instanceof HttpMethodNotAllowedException) {
            return Problem::methodNotAllowed(array_values(array_map(strval(...), $exception->getAllowedMethods())));
        }
        if ($exception instanceof HttpException) {
            // The framework's own titles and descriptions are not part of our contract, so only the
            // status is taken from it. A status without an entry here is not one this API sends.
            $problem = match ($exception->getCode()) {
                400 => Problem::badRequest(),
                401 => Problem::unauthorized(),
                403 => Problem::forbidden(),
                404 => Problem::notFound(),
                default => null,
            };
            if ($problem !== null) {
                return $problem;
            }
        }

        if (!$displayErrorDetails) {
            return Problem::internalError();
        }

        return Problem::internalError(sprintf(
            '%s: %s (%s:%d)',
            $exception::class,
            $exception->getMessage(),
            basename($exception->getFile()),
            $exception->getLine(),
        ));
    }

    private function log(ServerRequestInterface $request, Throwable $exception): void
    {
        $previous = [];
        for ($cause = $exception->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
            $previous[] = $cause::class . ': ' . $cause->getMessage();
        }

        $this->logger->error('Unhandled exception: ' . $exception::class . ': ' . $exception->getMessage(), [
            'method' => $request->getMethod(),
            // Without the query string, which can carry tokens.
            'path' => $request->getUri()->getPath(),
            'exception' => $exception::class,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'previous' => $previous,
            'trace' => $this->trace($exception),
        ]);
    }

    /**
     * The call stack as "Class->method (file:line)", without the arguments.
     *
     * PHP's own getTraceAsString() prints the argument values of every call, which is where passwords
     * and tokens would get into the log.
     *
     * @return list<string>
     */
    private function trace(Throwable $exception): array
    {
        $frames = [];
        foreach (array_slice($exception->getTrace(), 0, self::MAX_TRACE_FRAMES) as $frame) {
            $frames[] = ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function']
                . ' (' . ($frame['file'] ?? 'internal') . ':' . ($frame['line'] ?? 0) . ')';
        }

        return $frames;
    }
}
