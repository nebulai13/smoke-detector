<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support;

use DateTimeImmutable;
use SmokeDetector\Application\Account\Avatar;
use SmokeDetector\Application\Account\DisplayName;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Account\TimeZone;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserId;

/**
 * Accounts for integration tests, with a password whose real Argon2id hash is written down here:
 * storing a test account then costs no hashing time (a tenth of a second each, on purpose).
 */
final class TestAccounts
{
    /** The password of every account made by user(). */
    public const string PASSWORD = 'correct horse battery staple';

    /**
     * password_hash(PASSWORD) with the parameters of Argon2idPasswordHasher. A test checks that the
     * two still belong together.
     */
    public const string PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$U1F6dVppbUN5cThEaGtRdw$U/Mw636+mnoATUzc71bv20wywtFeIc0A/tu8L3aQw1E';

    /**
     * The versions of the consent texts that are in force in the test run (backend/phpunit.xml). A
     * test checks that they are what GET /legal/consents answers.
     */
    public const array CONSENT_VERSIONS = ['age18' => 'test-age18-v1', 'art9' => 'test-art9-v1', 'privacy' => 'test-privacy-v1'];

    public static function user(
        string $id,
        string $email,
        ?DateTimeImmutable $verifiedAt,
        DateTimeImmutable $createdAt,
        Locale $locale = Locale::De,
    ): User {
        return new User(
            UserId::fromString($id),
            EmailAddress::fromInput($email),
            self::PASSWORD_HASH,
            DisplayName::fromInput('Test User'),
            Avatar::fromInput('avatar-01'),
            $locale,
            TimeZone::fromInput('Europe/Berlin'),
            $verifiedAt,
            $createdAt,
        );
    }

    /**
     * A valid body for POST /auth/register.
     *
     * @param array<string, mixed> $overrides Members to replace.
     *
     * @return array<string, mixed>
     */
    public static function registration(array $overrides = []): array
    {
        return array_replace([
            'email' => 'anna@example.org',
            'password' => self::PASSWORD,
            'displayName' => 'Anna',
            'avatar' => 'avatar-03',
            'locale' => 'de',
            'timeZone' => 'Europe/Berlin',
            'consents' => self::consents(),
        ], $overrides);
    }

    /**
     * The member "consents" of a registration: all three accepted, each with the version of its
     * text that is in force, as an app that is up to date sends them.
     *
     * @param array<string, array<string, mixed>> $overrides Members to replace, by consent:
     *                                                       ['art9' => ['accepted' => false]].
     *
     * @return array<string, array<string, mixed>>
     */
    public static function consents(array $overrides = []): array
    {
        $consents = [];
        foreach (self::CONSENT_VERSIONS as $type => $version) {
            $consents[$type] = array_replace(['accepted' => true, 'version' => $version], $overrides[$type] ?? []);
        }

        return $consents;
    }
}
