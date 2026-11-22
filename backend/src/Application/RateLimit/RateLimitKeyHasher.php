<?php

declare(strict_types=1);

namespace SmokeDetector\Application\RateLimit;

use SensitiveParameter;

/**
 * Turns what a rate-limit key is made of (an email address, a client address, a user id) into the
 * hash that is stored in its place (HLD §4.3 "rate_limits", NFR-05).
 *
 * The hash has a secret key. With a plain hash, whoever can read the table (a backup, an SQL
 * injection somewhere) could test whether a given address is in it, and for IPv4 simply try them
 * all.
 */
interface RateLimitKeyHasher
{
    /**
     * @return string 32 raw bytes. The same value always gives the same bytes.
     */
    public function hash(#[SensitiveParameter] string $value): string;
}
