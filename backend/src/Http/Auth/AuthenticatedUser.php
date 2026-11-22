<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Auth;

use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Account\UserId;

/**
 * Who is making the request, for actions behind the AuthenticationMiddleware:
 *
 *     $userId = AuthenticatedUser::id($request);
 *
 * The id comes from the verified access token. An action never takes a user id from the path or the
 * body to decide whose data it touches.
 */
final readonly class AuthenticatedUser
{
    private const string ATTRIBUTE = 'authenticatedUserId';

    /**
     * @throws LogicException When the route is not behind the AuthenticationMiddleware: a bug, and
     *                        one that must never be answered as if nobody needed to be logged in.
     */
    public static function id(ServerRequestInterface $request): UserId
    {
        $userId = $request->getAttribute(self::ATTRIBUTE);
        if (!$userId instanceof UserId) {
            throw new LogicException('This route is not behind the AuthenticationMiddleware.');
        }

        return $userId;
    }

    /**
     * @internal Only the AuthenticationMiddleware says who the user is.
     */
    public static function attach(ServerRequestInterface $request, UserId $userId): ServerRequestInterface
    {
        return $request->withAttribute(self::ATTRIBUTE, $userId);
    }
}
