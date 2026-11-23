<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use DateTimeImmutable;
use SensitiveParameter;
use SmokeDetector\Application\Account\UserId;

/**
 * The session of one login, that is of one device, with its current refresh token (HLD §4.3
 * "refresh_tokens"). There is one per login, however often the token is exchanged: a refresh
 * replaces the token in place.
 *
 * A refresh token is "<key>.<secret>". The key stays the same for the whole session and is in no
 * other place than its tokens. The secret is new after every refresh: it follows from the key and
 * the generation of the token (RefreshSecrets). Neither is stored, only their hashes and the
 * generation: the key's hash to find the session, the whole token's to tell the current token from
 * any other, and the generation to make the current token and the one before it again when the
 * session's key is presented (Sessions).
 */
final readonly class RefreshToken
{
    public function __construct(
        /** The session. Stays the same from login to logout. */
        public string $id,
        public UserId $userId,
        /** TokenHash::of() the key. */
        #[SensitiveParameter]
        public string $keyHash,
        /** TokenHash::of() the whole current token. */
        #[SensitiveParameter]
        public string $tokenHash,
        public ?DeviceLabel $deviceLabel,
        /** The login. */
        public DateTimeImmutable $createdAt,
        /** The last refresh. Null when the token of the login was never exchanged. */
        public ?DateTimeImmutable $rotatedAt,
        /** From this moment on the token is no longer accepted: 60 days after it was issued. */
        public DateTimeImmutable $expiresAt,
        /** When the session was ended: by logout, by a password reset or because of a reuse. */
        public ?DateTimeImmutable $revokedAt,
        /** Which token of the session the current one is: 0 for the token of the login, one more after every refresh. */
        public int $generation = 0,
    ) {
    }
}
