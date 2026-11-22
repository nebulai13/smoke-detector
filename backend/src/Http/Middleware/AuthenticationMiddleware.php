<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SmokeDetector\Application\Auth\Authenticate;
use SmokeDetector\Application\Auth\InvalidAccessToken;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\Problem\Problem;
use SmokeDetector\Http\Problem\ProblemException;

/**
 * Lets a request through only with a valid access token: "Authorization: Bearer <JWT>" (HLD §4.5).
 *
 * Added to every route whose operation in api/openapi.yaml does not say "security: []" (see Routes).
 * Whatever is wrong with the token (missing, malformed, expired, wrong signature, wrong algorithm,
 * or its account was deleted), the answer is the same 401 "unauthorized": a client only needs to
 * know that it must refresh or log in, and an attacker should learn nothing more. The action reads
 * the user with AuthenticatedUser::id($request).
 */
final readonly class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(private Authenticate $authenticate)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Exactly one header, the scheme "Bearer" (in any case, RFC 9110), one space, the token.
        $headers = $request->getHeader('Authorization');
        if (count($headers) !== 1 || preg_match('/\ABearer ([A-Za-z0-9_.-]+)\z/Di', $headers[0], $match) !== 1) {
            throw new ProblemException(Problem::unauthorized());
        }

        try {
            $userId = $this->authenticate->execute($match[1]);
        } catch (InvalidAccessToken) {
            // Without the reason, and without the original exception: nothing about the token is logged.
            throw new ProblemException(Problem::unauthorized());
        }

        return $handler->handle(AuthenticatedUser::attach($request, $userId));
    }
}
