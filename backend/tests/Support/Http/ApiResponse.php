<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Http;

use JsonException;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;

/**
 * The response of an HTTP test, with the few accessors tests need.
 */
final readonly class ApiResponse
{
    public function __construct(public ResponseInterface $response)
    {
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    /** The header value, or '' when the response has no such header. */
    public function header(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    public function body(): string
    {
        return (string) $this->response->getBody();
    }

    /**
     * The body as a decoded JSON object.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        try {
            $data = json_decode($this->body(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            Assert::fail('The response body is not JSON (' . $exception->getMessage() . '): ' . $this->body());
        }
        Assert::assertIsArray($data, 'The response body is not a JSON object: ' . $this->body());

        $object = [];
        foreach ($data as $member => $value) {
            $object[(string) $member] = $value;
        }

        return $object;
    }
}
