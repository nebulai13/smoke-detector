<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use SensitiveParameter;

/**
 * How a secret token is stored (HLD §4.5): as its SHA-256 hash only.
 *
 * Whoever reads the database cannot use what is in it. A plain, fast hash is enough here, unlike for
 * passwords: a token is 256 random bits, so there is nothing to guess. Looking a token up by its
 * hash also makes the comparison independent of the token's characters (no timing side channel).
 */
final readonly class TokenHash
{
    /**
     * @return string 64 hexadecimal characters.
     */
    public static function of(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }
}
