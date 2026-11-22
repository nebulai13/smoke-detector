<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Privacy;

use SmokeDetector\Application\RateLimit\RateLimit;

/**
 * The rate limits of the data export (HLD §4.5 "Rate limits": fixed windows in `rate_limits`).
 *
 * Account deletion has no rule of its own: a wrong password there counts as a failed login
 * (FailedLogins), because it is the same password that is being guessed.
 */
final readonly class PrivacyLimits
{
    /**
     * Exports of one account: 5 per hour. An export reads every table of the user, and a stolen
     * access token must not be able to make the server do that without end.
     */
    public static function exportsPerAccount(): RateLimit
    {
        return new RateLimit('export.account', 5, 3600);
    }
}
