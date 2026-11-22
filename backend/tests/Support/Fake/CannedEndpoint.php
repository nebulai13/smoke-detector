<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * A route handler that answers with whatever the test prepared: for the tests of the test harness,
 * which need an endpoint that misbehaves on demand.
 */
final class CannedEndpoint
{
    public ResponseInterface $response;

    public int $calls = 0;

    public function __construct()
    {
        $this->response = new Response(204);
    }

    public function answer(int $status, string $contentType, string $body): void
    {
        $this->response = new Response($status, ['Content-Type' => $contentType], $body);
    }

    public function __invoke(): ResponseInterface
    {
        $this->calls++;

        return $this->response;
    }
}
