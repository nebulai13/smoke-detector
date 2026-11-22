<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Http\Auth\AuthenticatedUser;
use SmokeDetector\Http\JsonResponder;

/**
 * A test-only action for a route behind the AuthenticationMiddleware: answers with the user the
 * middleware found, the way a real action reads it.
 */
final readonly class WhoAmIEndpoint
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $factory = new Psr17Factory();

        return (new JsonResponder($factory, $factory))->json(['userId' => AuthenticatedUser::id($request)->value]);
    }
}
