<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Input;

use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Application\Validation\ValidationFailed;
use SmokeDetector\Http\RequestBody;

/**
 * The body {"userId": "<uuid>"} (schema UserReference) of the operations about another user. The id
 * travels in the body, never in the path: a path ends up in server logs (HLD §4.7).
 */
final readonly class UserReference
{
    /**
     * @throws ValidationFailed When the body is not exactly one well-formed user id.
     */
    public static function read(ServerRequestInterface $request): UserId
    {
        $fields = new Fields(RequestBody::of($request), ['userId']);
        $userId = $fields->make('userId', self::userId(...));
        if ($userId === null || $fields->hasErrors()) {
            throw $fields->failure();
        }

        return $userId;
    }

    /**
     * Any UUID, in either case. Whether such a user exists is the use case's business, and its
     * answer is the same as for a user who is no friend.
     *
     * @throws InvalidValue
     */
    private static function userId(string $value): UserId
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value) !== 1) {
            throw new InvalidValue(ErrorCode::InvalidFormat, 'Must be a UUID.');
        }

        return UserId::fromString(strtolower($value));
    }
}
