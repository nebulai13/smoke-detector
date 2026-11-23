<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * What a client gets when it logs in or refreshes: a short-lived access token and the refresh
 * token to get the next one with.
 */
final readonly class Session
{
    public function __construct(
        public AccessToken $accessToken,
        /** Shown once, here. The server keeps its hash only. */
        #[SensitiveParameter]
        public string $refreshToken,
        public DateTimeImmutable $refreshTokenExpiresAt,
    ) {
    }
}
