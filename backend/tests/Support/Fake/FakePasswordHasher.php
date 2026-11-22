<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use SmokeDetector\Application\Auth\PasswordHasher;

/**
 * A fast, transparent stand-in for Argon2id in unit tests. A hash is "fake-v<version>:" plus the
 * password, so a test can see exactly what was hashed, and with which "parameters".
 *
 * Not final: a test may extend it to make something happen while a password is being checked.
 */
class FakePasswordHasher implements PasswordHasher
{
    public const string DUMMY_HASH = 'fake-v1:<nobody>';

    /** The current "parameters". Raise it to make every existing hash outdated. */
    public int $version = 1;

    /** @var list<string> The passwords hash() was called with. */
    public array $hashed = [];

    /** @var list<array{password: string, hash: string}> The calls of verify(). */
    public array $verified = [];

    public function hash(string $password): string
    {
        $this->hashed[] = $password;

        return "fake-v{$this->version}:{$password}";
    }

    public function verify(string $password, string $hash): bool
    {
        $this->verified[] = ['password' => $password, 'hash' => $hash];

        return $hash !== self::DUMMY_HASH && preg_match('/\Afake-v\d+:(.*)\z/s', $hash, $match) === 1 && $match[1] === $password;
    }

    public function needsRehash(string $hash): bool
    {
        return !str_starts_with($hash, "fake-v{$this->version}:");
    }

    public function dummyHash(): string
    {
        return self::DUMMY_HASH;
    }
}
