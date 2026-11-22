<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use SmokeDetector\Application\Auth\RefreshSecrets;

/**
 * Readable refresh token secrets for unit tests: "secret-<generation>-of-<session key>". Like the
 * real ones they follow from the key and the generation alone, so a test can write down every
 * token a session ever has: FakeRefreshSecrets::token('token-1', 2).
 */
final class FakeRefreshSecrets implements RefreshSecrets
{
    /** @var list<array{string, int}> Key and generation of every call. */
    public array $asked = [];

    public function secret(string $sessionKey, int $generation): string
    {
        $this->asked[] = [$sessionKey, $generation];

        return "secret-{$generation}-of-{$sessionKey}";
    }

    /**
     * The whole refresh token of that generation of the session.
     */
    public static function token(string $sessionKey, int $generation): string
    {
        return "{$sessionKey}.secret-{$generation}-of-{$sessionKey}";
    }
}
