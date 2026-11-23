<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Http\Middleware;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SmokeDetector\Http\Middleware\SecurityHeadersMiddleware;
use SmokeDetector\Tests\Support\Fake\CapturingRequestHandler;

/**
 * Headers every response of the JSON API carries.
 */
final class SecurityHeadersMiddlewareTest extends TestCase
{
    public function test_NFR_05_every_response_tells_the_browser_to_use_https_only(): void
    {
        $response = $this->responseFor(new Response(200));

        // HSTS (NFR-05) for one year: after the first visit a browser never talks plain http to the site again.
        self::assertSame('max-age=31536000; includeSubDomains', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function test_responses_are_never_cached(): void
    {
        // Responses contain personal and health data. They must not stay in a shared or a browser cache.
        self::assertSame('no-store', $this->responseFor(new Response(200))->getHeaderLine('Cache-Control'));
    }

    public function test_a_caching_header_of_the_application_is_replaced(): void
    {
        $response = $this->responseFor((new Response(200))->withHeader('Cache-Control', 'public, max-age=3600'));

        self::assertSame(['no-store'], $response->getHeader('Cache-Control'));
    }

    public function test_the_browser_must_not_guess_the_content_type_or_render_the_response(): void
    {
        $response = $this->responseFor(new Response(200));

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        // A JSON response loads nothing and is never shown in a frame.
        self::assertSame("default-src 'none'; frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Resource-Policy'));
    }

    public function test_status_body_and_other_headers_are_left_alone(): void
    {
        $inner = (new Response(422, ['Content-Type' => 'application/problem+json'], '{"type":"validation_failed"}'));

        $response = $this->responseFor($inner);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"type":"validation_failed"}', (string) $response->getBody());
    }

    private function responseFor(Response $inner): ResponseInterface
    {
        return (new SecurityHeadersMiddleware())->process(new ServerRequest('GET', '/api/v1/health'), new CapturingRequestHandler($inner));
    }
}
