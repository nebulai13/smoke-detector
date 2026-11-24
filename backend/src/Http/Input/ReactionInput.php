<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Input;

use Psr\Http\Message\ServerRequestInterface;
use SmokeDetector\Application\Feed\ReactionKey;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Application\Validation\ValidationFailed;
use SmokeDetector\Http\RequestBody;

/**
 * The body of POST /feed/reactions (schema ReactionRequest): {"entryId": "<uuid>", "reaction": "cheer"}.
 * The id travels in the body, never in the path: a path ends up in server logs (HLD §4.7).
 */
final readonly class ReactionInput
{
    /**
     * @return array{string, ReactionKey} The id of the entry, lower-cased, and the reaction.
     *
     * @throws ValidationFailed With every error at once. A reaction that is not one of ReactionKey
     *                          is "invalid_value".
     */
    public static function read(ServerRequestInterface $request): array
    {
        $fields = new Fields(RequestBody::of($request), ['entryId', 'reaction']);
        $entryId = $fields->make('entryId', self::entryId(...));
        $reaction = $fields->enum('reaction', ReactionKey::class);
        if ($entryId === null || $reaction === null || $fields->hasErrors()) {
            throw $fields->failure();
        }

        return [$entryId, $reaction];
    }

    /**
     * Any UUID, in either case. Whether the user may see such an entry is the use case's business.
     *
     * @throws InvalidValue
     */
    private static function entryId(string $value): string
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value) !== 1) {
            throw new InvalidValue(ErrorCode::InvalidFormat, 'Must be a UUID.');
        }

        return strtolower($value);
    }
}
