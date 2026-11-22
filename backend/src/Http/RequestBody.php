<?php

declare(strict_types=1);

namespace SmokeDetector\Http;

use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The JSON object of a request, as an action reads it.
 *
 * JsonBodyMiddleware has already made sure that the body is a JSON object of acceptable size.
 * What the members must look like is the action's own, explicit validation (HLD §7): the OpenAPI
 * schema is enforced by the tests, not at run time.
 */
final readonly class RequestBody
{
    /**
     * @return array<array-key, mixed> Member name => value. Empty when the request had no body, so that an
     *                                 action reports each required field as missing.
     */
    public static function of(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if ($parsed === null) {
            return [];
        }
        if (!is_array($parsed)) {
            throw new LogicException('The request body was not parsed by JsonBodyMiddleware.');
        }

        return $parsed;
    }
}
