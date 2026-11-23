<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use SmokeDetector\Application\Account\Avatar;
use SmokeDetector\Application\Account\DisplayName;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Account\TimeZone;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Auth\DeviceLabel;
use SmokeDetector\Application\Auth\OneTimeToken;
use SmokeDetector\Application\Auth\RefreshToken;
use SmokeDetector\Application\Auth\TokenPurpose;
use SmokeDetector\Application\Mail\MailKind;
use SmokeDetector\Application\Mail\QueuedMail;
use SmokeDetector\Application\Plan\BaselineEntry;
use SmokeDetector\Application\Plan\Plan;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Plan\Baseline;
use SmokeDetector\Domain\Plan\BaselineSource;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;

/**
 * Builders for the objects tests store, with a default for everything a test does not care about.
 */
final class TestData
{
    /** A UUID that ends in the number: readable in a failure message, unique per number. */
    public static function uuid(int $number): string
    {
        return sprintf('00000000-0000-7000-8000-%012d', $number);
    }

    public static function userId(int $number): UserId
    {
        return UserId::fromString(self::uuid($number));
    }

    /** @param string $utc "YYYY-MM-DD HH:MM:SS" in UTC. */
    public static function time(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    public static function user(
        int $number = 1,
        ?string $email = null,
        string $passwordHash = 'not-a-real-hash',
        ?string $verifiedAt = '2026-11-02 08:00:00',
        string $createdAt = '2026-11-01 07:00:00',
        string $displayName = 'Änna 🔥',
        Locale $locale = Locale::De,
    ): User {
        return new User(
            self::userId($number),
            EmailAddress::fromInput($email ?? "user{$number}@example.org"),
            $passwordHash,
            DisplayName::fromInput($displayName),
            Avatar::fromInput('avatar-03'),
            $locale,
            TimeZone::fromInput('Europe/Berlin'),
            $verifiedAt === null ? null : self::time($verifiedAt),
            self::time($createdAt),
        );
    }

    public static function oneTimeToken(
        int $number,
        UserId $userId,
        string $token,
        TokenPurpose $purpose = TokenPurpose::VerifyEmail,
        string $expiresAt = '2026-11-18 12:00:00',
        ?string $usedAt = null,
        int $failedAttempts = 0,
    ): OneTimeToken {
        return new OneTimeToken(
            self::uuid($number),
            $userId,
            $purpose,
            hash('sha256', $token),
            self::time($expiresAt),
            $usedAt === null ? null : self::time($usedAt),
            $failedAttempts,
        );
    }

    /**
     * A mail in the outbox of user $userNumber, to "user<number>@example.org" unless said otherwise.
     * Queued at 12:00:00 on 2026-11-17 and due at once, without a failed attempt.
     *
     * @param string $sealedValues What the outbox stores for the values of the template: encrypted
     *                             text. FakeMailValuesCipher::seal() makes one a unit test can open.
     */
    public static function queuedMail(
        int $number,
        int $userNumber = 1,
        MailKind $kind = MailKind::ConfirmEmail,
        string $sealedValues = 'sealed-values',
        string $createdAt = '2026-11-17 12:00:00',
        ?string $notBefore = null,
        int $attempts = 0,
        Locale $locale = Locale::De,
        ?string $to = null,
    ): QueuedMail {
        return new QueuedMail(
            self::uuid($number),
            self::userId($userNumber),
            $kind,
            $locale,
            EmailAddress::fromInput($to ?? "user{$userNumber}@example.org"),
            $sealedValues,
            self::time($createdAt),
            self::time($notBefore ?? $createdAt),
            $attempts,
        );
    }

    /**
     * The session of a login whose current refresh token is "<key>.<secret>".
     */
    public static function session(
        int $number,
        UserId $userId,
        string $key,
        string $secret,
        string $expiresAt = '2027-01-16 12:00:00',
        ?string $rotatedAt = null,
        ?string $revokedAt = null,
        ?string $deviceLabel = null,
        string $createdAt = '2026-11-17 12:00:00',
        int $generation = 0,
    ): RefreshToken {
        return new RefreshToken(
            self::uuid($number),
            $userId,
            hash('sha256', $key),
            hash('sha256', "{$key}.{$secret}"),
            $deviceLabel === null ? null : DeviceLabel::fromInput($deviceLabel),
            self::time($createdAt),
            $rotatedAt === null ? null : self::time($rotatedAt),
            self::time($expiresAt),
            $revokedAt === null ? null : self::time($revokedAt),
            $generation,
        );
    }

        /** The plan of user $userNumber. Without a goal: reduce to 5. */
    public static function plan(
        int $userNumber = 1,
        ?Goal $goal = null,
        TaperSpeed $taperSpeed = TaperSpeed::Normal,
        ProductType $defaultProduct = ProductType::Cigarette,
        string $startDate = '2026-11-17',
        string $rulesVersion = 'v1',
    ): Plan {
        return new Plan(
            self::userId($userNumber),
            $goal ?? Goal::reduceTo(5),
            $taperSpeed,
            $defaultProduct,
            LocalDate::fromString($startDate),
            $rulesVersion,
        );
    }

    /** A baseline history entry of user $userNumber. The value is in tenths: 74 is 7.4 sessions per day. */
    public static function baseline(
        int $userNumber,
        string $validFrom,
        int $tenths,
        BaselineSource $source = BaselineSource::Onboarding,
        ?float $weekMean = null,
        ?float $monthMean = null,
        ?int $weekDays = null,
        ?int $monthDays = null,
        string $rulesVersion = 'v1',
    ): BaselineEntry {
        return new BaselineEntry(
            self::userId($userNumber),
            LocalDate::fromString($validFrom),
            Baseline::fromTenths($tenths),
            $source,
            $weekMean,
            $monthMean,
            $weekDays,
            $monthDays,
            $rulesVersion,
        );
    }
}
