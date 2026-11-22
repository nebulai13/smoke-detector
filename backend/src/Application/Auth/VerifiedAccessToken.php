<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use SmokeDetector\Application\Account\UserId;

/**
 * What a genuine, unexpired access token says.
 */
final readonly class VerifiedAccessToken
{
    public function __construct(
        public UserId $userId,
        /** User::$tokenVersion of the account when the token was issued. */
        public int $tokenVersion,
    ) {
    }
}
