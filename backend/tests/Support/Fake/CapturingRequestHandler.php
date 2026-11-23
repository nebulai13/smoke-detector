<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stands in for "the rest of the application" behind a middleware: remembers the request it was
 * given and answers with a prepared response.
 */
final class CapturingRequestHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $request = null;

    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return $this->response;
    }
}
