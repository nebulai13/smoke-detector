<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use SensitiveParameter;

/**
 * Hashes and checks passwords (NFR-05: Argon2id).
 *
 * The password goes in exactly as the user typed it: nothing trims, cuts or re-encodes it.
 */
interface PasswordHasher
{
    public function hash(#[SensitiveParameter] string $password): string;

    public function verify(#[SensitiveParameter] string $password, #[SensitiveParameter] string $hash): bool;

    /**
     * Whether the hash was made with other parameters than hash() uses now, and should be replaced
     * the next time the password is at hand.
     */
    public function needsRehash(#[SensitiveParameter] string $hash): bool;

    /**
     * A hash of no password at all, made with the current parameters. Login checks the password
     * against it when there is no account, so that the answer takes as long as for an existing one.
     * verify() against it costs the same as against a real hash and never succeeds.
     */
    public function dummyHash(): string;
}
