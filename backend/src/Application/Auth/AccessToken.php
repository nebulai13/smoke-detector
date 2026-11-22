<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use SensitiveParameter;

/**
 * A signed access token as it is handed to the client.
 */
final readonly class AccessToken
{
    public function __construct(
        #[SensitiveParameter]
        public string $token,
        /** How long it is valid from now on. */
        public int $expiresInSeconds,
    ) {
    }
}
