<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use DateTimeImmutable;
use SensitiveParameter;
use SmokeDetector\Application\Account\UserId;

/**
 * A stored one-time token (HLD §4.3 "one_time_tokens"). The token itself is not in it.
 */
final readonly class OneTimeToken
{
    public function __construct(
        public string $id,
        public UserId $userId,
        public TokenPurpose $purpose,
        /** TokenHash::of() the token. */
        #[SensitiveParameter]
        public string $tokenHash,
        /** From this moment on the token is no longer accepted. */
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $usedAt,
        /**
         * How often a wrong password was entered with this token (confirmation links only, see
         * VerifyEmail). A new token starts at 0.
         */
        public int $failedAttempts = 0,
    ) {
    }
}
