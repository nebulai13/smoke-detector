<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Headers every response of the API carries, error responses included (it is the outermost middleware).
 *
 * They are set here and not only in .htaccess, so that they are tested and do not depend on the
 * web server configuration.
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public const array HEADERS = [
        // HTTPS only (NFR-05). Browsers ignore the header on plain http, so it is safe to send always.
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        // Responses hold personal and health data: no cache, shared or private, may keep them.
        'Cache-Control' => 'no-store',
        // The declared content type is final. A browser must not "detect" HTML in a JSON string.
        'X-Content-Type-Options' => 'nosniff',
        // A JSON response loads nothing and is never shown inside a frame.
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
        // The web app is served from the same origin (HLD AD-9). No other site may embed API responses.
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return self::secure($handler->handle($request));
    }

    /**
     * Adds the headers to a response. Also used for the response of an application that could not start.
     */
    public static function secure(ResponseInterface $response): ResponseInterface
    {
        foreach (self::HEADERS as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
