<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

/**
 * What a one-time token from a mail link is good for (HLD §4.3 "one_time_tokens").
 */
enum TokenPurpose: string
{
    case VerifyEmail = 'verify_email';
    case ResetPassword = 'reset_password';

    /**
     * How long the link works: 24 h to confirm an address (FR-ACC-03), 1 h to reset a password
     * (FR-ACC-05).
     */
    public function lifetimeSeconds(): int
    {
        return match ($this) {
            self::VerifyEmail => 86_400,
            self::ResetPassword => 3_600,
        };
    }
}
