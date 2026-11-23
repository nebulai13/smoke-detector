<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Auth;

use SmokeDetector\Application\Auth\TokenGenerator;

/**
 * Tokens from the operating system's secure random source (HLD §4.5: 32 random bytes, base64url).
 */
final readonly class RandomTokenGenerator implements TokenGenerator
{
    private const int BYTES = 32;

    public function generate(): string
    {
        // random_bytes() throws when the system has no secure randomness. It never returns weak bytes.
        return rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '=');
    }
}
