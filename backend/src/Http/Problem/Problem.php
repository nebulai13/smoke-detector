<?php

declare(strict_types=1);

namespace SmokeDetector\Http\Problem;

use InvalidArgumentException;
use SmokeDetector\Application\Validation\FieldError;

/**
 * RFC 9457 problem details: the body of every error response (HLD §4.5 "Errors").
 *
 * Clients decide by the type, a stable slug such as "validation_failed". Title and detail are for
 * developers. A new kind of error gets its own slug, its response in api/openapi.yaml, and is thrown
 * from an action as a ProblemException.
 */
final readonly class Problem
{
    private const array STANDARD_MEMBERS = ['type', 'title', 'status', 'detail', 'instance'];

    /**
     * @param array<string, mixed>  $extensions Further members of the body. Each must be in the response schema.
     * @param array<string, string> $headers    Response headers that belong to the problem, e.g. Retry-After.
     */
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public ?string $detail = null,
        public array $extensions = [],
        public array $headers = [],
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $type) !== 1) {
            throw new InvalidArgumentException(
                'A problem type must be a slug of a-z, 0-9 and "_" that starts with a letter.',
            );
        }
        if ($title === '') {
            throw new InvalidArgumentException('A problem needs a title.');
        }
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException('A problem status must be from 400 to 599.');
        }
        foreach (array_keys($extensions) as $member) {
            if (in_array($member, self::STANDARD_MEMBERS, true)) {
                throw new InvalidArgumentException("A problem extension cannot be named \"{$member}\".");
            }
        }
    }

    public static function badRequest(): self
    {
        return new self('bad_request', 'Bad request', 400);
    }

    public static function malformedJson(string $detail): self
    {
        return new self('malformed_json', 'Malformed JSON', 400, $detail);
    }

    /**
     * The access token is missing or not accepted. Never with a reason.
     */
    public static function unauthorized(): self
    {
        return new self('unauthorized', 'Unauthorized', 401, null, [], ['WWW-Authenticate' => 'Bearer']);
    }

    /**
     * Login failed: unknown email address, wrong password, or a registration that is not confirmed
     * yet. On purpose not told apart (FR-ACC-01, FR-ACC-04).
     */
    public static function invalidCredentials(): self
    {
        return new self('invalid_credentials', 'Invalid credentials', 401, 'The email address or the password is wrong.');
    }

    /**
     * The password that was entered again on an authenticated request is wrong (FR-ACC-08). The
     * type of a failed login, and deliberately not "unauthorized": the access token is fine, and a
     * client that refreshed it and tried again would only use up the user's attempts.
     */
    public static function wrongPassword(): self
    {
        return new self('invalid_credentials', 'Invalid credentials', 401, 'The password is wrong.');
    }

    /**
     * The link of the confirmation mail is fine, but the password entered with it is not the one
     * of the registration it belongs to (FR-ACC-03). The type of a failed login. Nothing of the
     * registration is in it: whoever holds the link has not shown yet that it is theirs.
     */
    public static function wrongSignUpPassword(): self
    {
        return new self('invalid_credentials', 'Invalid credentials', 401, 'The password is not the one of this sign-up.');
    }

    /**
     * The token of a mail link is not accepted: unknown, expired and used are not told apart.
     */
    public static function invalidToken(): self
    {
        return new self('invalid_token', 'Invalid token', 400, 'The token is unknown, expired or already used.');
    }

    /**
     * The refresh token is not accepted: missing, unknown, expired, used and revoked are not told apart.
     */
    public static function invalidRefreshToken(): self
    {
        return new self('invalid_refresh_token', 'Invalid refresh token', 401, 'Log in again.');
    }

    /**
     * @param int $retryAfterSeconds Until the rate limit window ends. Reported as at least 1.
     */
    public static function rateLimited(int $retryAfterSeconds): self
    {
        $seconds = max(1, $retryAfterSeconds);

        return new self(
            'rate_limited',
            'Too many requests',
            429,
            'Try again in ' . $seconds . ($seconds === 1 ? ' second.' : ' seconds.'),
            [],
            ['Retry-After' => (string) $seconds],
        );
    }

    public static function forbidden(): self
    {
        return new self('forbidden', 'Forbidden', 403);
    }

    public static function notFound(): self
    {
        return new self('not_found', 'Not found', 404);
    }

    /**
     * @param list<string> $allowedMethods
     */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self('method_not_allowed', 'Method not allowed', 405, null, [], ['Allow' => implode(', ', $allowedMethods)]);
    }

    public static function payloadTooLarge(int $maxBytes): self
    {
        return new self(
            'payload_too_large',
            'Payload too large',
            413,
            "The request body may be at most {$maxBytes} bytes long.",
        );
    }

    public static function unsupportedMediaType(): self
    {
        return new self(
            'unsupported_media_type',
            'Unsupported media type',
            415,
            'The request body must be sent as application/json.',
        );
    }

    /**
     * @param non-empty-list<FieldError> $errors
     */
    public static function validationFailed(array $errors): self
    {
        return new self('validation_failed', 'Validation failed', 422, null, [
            'errors' => array_map(
                static fn (FieldError $error): array => [
                    'field' => $error->field,
                    'code' => $error->code->value,
                    'message' => $error->message,
                ],
                $errors,
            ),
        ]);
    }

    /**
     * The user has not done the onboarding yet: logging, days and dashboard need the plan.
     */
    public static function planRequired(): self
    {
        return new self('plan_required', 'Plan required', 409, 'Set up the plan first (PUT /me/plan).');
    }

    /**
     * The day is final (FR-LOG-07): nothing about it changes any more.
     */
    public static function dayFinalized(): self
    {
        return new self('day_finalized', 'Day finalized', 409, 'The day is final and cannot be changed any more.');
    }

    /**
     * The day has not begun for the user.
     */
    public static function dayInFuture(): self
    {
        return new self('day_in_future', 'Day in the future', 409, 'The day has not begun yet.');
    }

    /**
     * The day is before the first day of the plan.
     */
    public static function dayBeforeStart(): self
    {
        return new self('day_before_start', 'Day before the start', 409, 'The day is before the first day of the plan.');
    }

    /**
     * Nobody the user can ask has this invite code: it does not exist (any more), or one of the two
     * has blocked the other. On purpose not told apart (HLD §4.7).
     */
    public static function inviteNotFound(): self
    {
        return new self('invite_not_found', 'Invite not found', 404, 'Nobody with this invite code can be asked.');
    }

    public static function ownInvite(): self
    {
        return new self('own_invite', 'Own invite', 409, 'This is the user\'s own invite code.');
    }

    public static function alreadyFriends(): self
    {
        return new self('already_friends', 'Already friends', 409, 'The two are friends already.');
    }

    /**
     * FR-FRND-04: at most 50 friends. Without a number of anybody's friends in it.
     */
    public static function friendLimitReached(): self
    {
        return new self('friend_limit_reached', 'Friend limit reached', 409, 'The limit of friends is reached.');
    }

    public static function friendRequestNotFound(): self
    {
        return new self('friend_request_not_found', 'Friend request not found', 404, 'There is no open friend request between the two in this direction.');
    }

    /**
     * The same for a user id that does not exist: the answer does not tell whether it does.
     */
    public static function friendNotFound(): self
    {
        return new self('friend_not_found', 'Friend not found', 404, 'There is no such friend.');
    }

    public static function blockNotFound(): self
    {
        return new self('block_not_found', 'Block not found', 404, 'The user has not blocked this user.');
    }

    /**
     * The user has no duel with this id. The same for an id that does not exist (HLD §4.8).
     */
    public static function duelNotFound(): self
    {
        return new self('duel_not_found', 'Duel not found', 404, 'The user has no duel with this id.');
    }

    /**
     * Not a proposal any more (answered, expired or taken back), or not for this user to do.
     */
    public static function duelNotOpen(): self
    {
        return new self('duel_not_open', 'Duel not open', 409, 'The duel is not a proposal this user can answer or take back.');
    }

    /**
     * FR-COMP-05: one open duel per pair of friends, whoever proposed it.
     */
    public static function duelExists(): self
    {
        return new self('duel_exists', 'Duel exists', 409, 'There is an open duel with this friend already.');
    }

    /**
     * FR-COMP-05: at most 5 open duels.
     */
    public static function duelLimitReached(): self
    {
        return new self('duel_limit_reached', 'Duel limit reached', 409, 'The limit of open duels is reached.');
    }

    /**
     * There is no such entry in the feed of the user. The same for an entry that does not exist and
     * for one the user may not see (HLD §4.13).
     */
    public static function feedEntryNotFound(): self
    {
        return new self('feed_entry_not_found', 'Feed entry not found', 404, 'There is no such entry in the feed of the user.');
    }

    /**
     * Nobody reacts to an own entry (HLD §4.13).
     */
    public static function ownFeedEntry(): self
    {
        return new self('own_feed_entry', 'Own feed entry', 409, 'Nobody reacts to an own entry.');
    }

    /**
     * One nudge per friend and day (HLD §4.14), whatever became of the first.
     */
    public static function alreadyNudged(): self
    {
        return new self('already_nudged', 'Already nudged', 409, 'The user has nudged this friend today already.');
    }

    /**
     * The period of the league has not begun for the user (HLD §4.9).
     */
    public static function leagueNotFound(): self
    {
        return new self('league_not_found', 'League not found', 404, 'The period has not begun for this user.');
    }

    /**
     * @param string|null $detail Only ever given outside production: it describes the exception.
     */
    public static function internalError(?string $detail = null): self
    {
        return new self('internal_error', 'Internal server error', 500, $detail);
    }

    /**
     * @param int|null $retryAfterSeconds When it makes sense to try again, if that can be said.
     *                                    Reported as at least 1.
     */
    public static function serviceUnavailable(string $detail, ?int $retryAfterSeconds = null): self
    {
        return new self(
            'service_unavailable',
            'Service unavailable',
            503,
            $detail,
            [],
            $retryAfterSeconds === null ? [] : ['Retry-After' => (string) max(1, $retryAfterSeconds)],
        );
    }

    /**
     * The JSON body: the standard members first, then the extension members.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = ['type' => $this->type, 'title' => $this->title, 'status' => $this->status];
        if ($this->detail !== null) {
            $body['detail'] = $this->detail;
        }

        return $body + $this->extensions;
    }
}
