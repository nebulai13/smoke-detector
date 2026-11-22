<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Middleware;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SmokeDetector\Http\Problem\Problem;
use SmokeDetector\Http\Problem\ProblemException;

/**
 * Reads the request body: a JSON object of limited size, or nothing.
 *
 * After this middleware an action finds the object as an array in getParsedBody() (null when the
 * request had no body). Anything else never reaches an action:
 * 413 payload_too_large, 415 unsupported_media_type or 400 malformed_json.
 *
 * A GET or HEAD request is passed on untouched: no operation of the contract takes a body with
 * them, and answering 400, 413 or 415 there would come before the access token is looked at, with
 * statuses the contract does not list for those operations. Its body is never read.
 */
final readonly class JsonBodyMiddleware implements MiddlewareInterface
{
    /**
     * 64 KiB. The largest request of the design, a batch of offline logs, stays far below that.
     * A limit keeps one request from occupying the memory of the shared host.
     */
    public const int DEFAULT_MAX_BYTES = 65_536;

    /** No request of this API nests deeper than a few levels. A limit keeps the parser's work bounded. */
    private const int MAX_DEPTH = 32;

    /** Methods whose requests carry no body this API reads. Method names are case-sensitive (RFC 9110). */
    private const array METHODS_WITHOUT_BODY = ['GET', 'HEAD'];

    public function __construct(private int $maxBytes = self::DEFAULT_MAX_BYTES)
    {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('The request body limit must be at least 1 byte.');
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), self::METHODS_WITHOUT_BODY, true)) {
            return $handler->handle($request);
        }

        // An honest client is turned away before its body is read at all.
        $declared = $request->getHeaderLine('Content-Length');
        if (preg_match('/\A[0-9]+\z/', $declared) === 1 && (strlen($declared) > 18 || (int) $declared > $this->maxBytes)) {
            throw new ProblemException(Problem::payloadTooLarge($this->maxBytes));
        }

        // The header may lie or be missing, so the body itself is measured as well.
        $body = $this->read($request->getBody());
        if (strlen($body) > $this->maxBytes) {
            throw new ProblemException(Problem::payloadTooLarge($this->maxBytes));
        }
        if ($body === '') {
            return $handler->handle($request);
        }

        if (!$this->isJson($request->getHeaderLine('Content-Type'))) {
            throw new ProblemException(Problem::unsupportedMediaType());
        }

        try {
            // Numbers beyond the integer range arrive as text instead of being rounded to a float.
            $data = json_decode($body, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new ProblemException(Problem::malformedJson('The request body is not valid JSON.'));
        }

        // Decoded as arrays, "{}" and "[]" look the same, hence the look at the first character.
        if (!is_array($data) || !str_starts_with(ltrim($body), '{')) {
            throw new ProblemException(Problem::malformedJson('The request body must be a JSON object.'));
        }

        return $handler->handle($request->withParsedBody($data));
    }

    /**
     * Reads at most one byte more than the limit: enough to know that the body is too large.
     */
    private function read(StreamInterface $stream): string
    {
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';
        while (!$stream->eof() && strlen($body) <= $this->maxBytes) {
            $chunk = $stream->read(min(8192, $this->maxBytes + 1 - strlen($body)));
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
        }

        return $body;
    }

    private function isJson(string $contentType): bool
    {
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

        return preg_match('~\Aapplication/(?:json|[a-z0-9.!#$&^_-]+\+json)\z~', $mediaType) === 1;
    }
}
