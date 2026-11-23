<?php

declare(strict_types=1);

namespace SmokeDetector\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SmokeDetector\Http\Problem\Problem;
use stdClass;

/**
 * Builds the responses this API sends: JSON data, RFC 9457 problem details, and "no content".
 */
final readonly class JsonResponder
{
    // Invalid UTF-8 is replaced instead of failing: an error response must always be produced, even
    // when the text it describes is broken.
    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {
    }

    /**
     * @param array<string, mixed> $data The members of the JSON object, exactly as the response schema in
     *                                   api/openapi.yaml names them.
     */
    public function json(array $data, int $status = 200): ResponseInterface
    {
        return $this->respond($data, $status, 'application/json');
    }

    /**
     * 204: done, and there is nothing to say about it.
     */
    public function noContent(): ResponseInterface
    {
        return $this->responses->createResponse(204);
    }

    public function problem(Problem $problem): ResponseInterface
    {
        $response = $this->respond($problem->toArray(), $problem->status, 'application/problem+json');
        foreach ($problem->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function respond(array $data, int $status, string $contentType): ResponseInterface
    {
        // An empty PHP array would be encoded as the list "[]". The body is always an object.
        $body = json_encode($data === [] ? new stdClass() : $data, self::FLAGS);

        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', $contentType)
            ->withBody($this->streams->createStream($body));
    }
}
