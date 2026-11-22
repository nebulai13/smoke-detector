<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Auth;

use RuntimeException;
use SensitiveParameter;
use SmokeDetector\Application\Auth\PasswordHasher;

/**
 * Argon2id through PHP's password_hash() (NFR-05, HLD §4.5).
 *
 * The parameters are written out instead of left to PHP's defaults, so that a PHP update cannot
 * change them unnoticed: 64 MiB of memory, 4 passes, 1 thread. That is well above the OWASP minimum
 * (19 MiB, 2 passes) and takes roughly a tenth of a second.
 *
 * To change them: change OPTIONS and replace DUMMY_HASH by a hash made with the new values (the
 * test compares the two). Existing hashes keep working and are replaced at each user's next login.
 */
final readonly class Argon2idPasswordHasher implements PasswordHasher
{
    private const array OPTIONS = ['memory_cost' => 65_536, 'time_cost' => 4, 'threads' => 1];

    /**
     * The hash of 32 random bytes that were thrown away, made with OPTIONS. Nobody knows a password
     * for it, and it is no secret.
     */
    private const string DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$Q0ZrNE9INHdtbGNqNTZoMQ$wlL4JU2LKr66OrhPF+Kns8AZYMLjrmv/+ljE2LEUrBk';

    public function __construct()
    {
        // Never fall back to a weaker algorithm silently: a PHP without Argon2id must not start.
        if (!defined('PASSWORD_ARGON2ID')) {
            throw new RuntimeException('This PHP was built without Argon2id, which the password hashing needs.');
        }
    }

    public function hash(#[SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    public function verify(#[SensitiveParameter] string $password, #[SensitiveParameter] string $hash): bool
    {
        // Reads algorithm and parameters from the hash itself, so older hashes (and bcrypt ones)
        // still verify. needsRehash() then says that they should be replaced.
        return password_verify($password, $hash);
    }

    public function needsRehash(#[SensitiveParameter] string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    public function dummyHash(): string
    {
        return self::DUMMY_HASH;
    }
}
