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
use SmokeDetector\Application\Competition\Duel;
use SmokeDetector\Application\Competition\DuelDecision;
use SmokeDetector\Application\Competition\DuelStatus;
use SmokeDetector\Application\Competition\DuelWinner;
use SmokeDetector\Application\Competition\SideResult;
use SmokeDetector\Application\Day\DayRecord;
use SmokeDetector\Application\Day\DayResult;
use SmokeDetector\Application\Day\ProgressRecord;
use SmokeDetector\Application\Day\XpEvent;
use SmokeDetector\Application\Friends\Block;
use SmokeDetector\Application\Friends\Friendship;
use SmokeDetector\Application\Logging\SmokeLog;
use SmokeDetector\Application\Mail\MailKind;
use SmokeDetector\Application\Mail\QueuedMail;
use SmokeDetector\Application\Plan\BaselineEntry;
use SmokeDetector\Application\Plan\Plan;
use SmokeDetector\Application\Plan\PlanTerms;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\Time\ZoneChange;
use SmokeDetector\Domain\Day\DayEvent;
use SmokeDetector\Domain\Day\UserProgress;
use SmokeDetector\Domain\Plan\Baseline;
use SmokeDetector\Domain\Plan\BaselineSource;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Domain\Streak\StreakState;
use SmokeDetector\Domain\Xp\XpSource;

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

    public static function date(string $value): LocalDate
    {
        return LocalDate::fromString($value);
    }

    /**
     * A log of user $userNumber with the id uuid($number): one cigarette at noon UTC of its local
     * date, stored at that moment and never changed, unless said otherwise.
     */
    public static function smokeLog(
        int $number,
        int $userNumber = 1,
        string $localDate = '2026-11-17',
        ?string $smokedAt = null,
        int $count = 1,
        ProductType $productType = ProductType::Cigarette,
        ?string $createdAt = null,
        ?string $updatedAt = null,
        ?string $deletedAt = null,
    ): SmokeLog {
        $smokedAt ??= "{$localDate} 12:00:00";
        $createdAt ??= $smokedAt;

        return new SmokeLog(
            self::uuid($number),
            self::userId($userNumber),
            self::time($smokedAt),
            self::date($localDate),
            $productType,
            $count,
            self::time($createdAt),
            self::time($updatedAt ?? $createdAt),
            $deletedAt === null ? null : self::time($deletedAt),
        );
    }

    /** A day of user $userNumber that is not final yet. */
    public static function day(int $userNumber, string $date, int $count = 0, ?string $checkedInAt = null, ?DayResult $result = null): DayRecord
    {
        return new DayRecord(
            self::userId($userNumber),
            self::date($date),
            $count,
            $checkedInAt === null ? null : self::time($checkedInAt),
            $result,
        );
    }

    /**
     * What finalization stores with a day.
     *
     * @param list<DayEvent> $events
     */
    public static function dayResult(
        int $dailyLimit = 9,
        int $xp = 0,
        array $events = [],
        bool $freezeUsed = false,
        string $finalizedAt = '2026-11-20 23:00:30',
        string $rulesVersion = 'v1',
    ): DayResult {
        return new DayResult($dailyLimit, $xp, $events, $freezeUsed, self::time($finalizedAt), $rulesVersion);
    }

    /**
     * The progress of user $userNumber. Without anything else: nothing earned yet, the first day to
     * finalize is the 17th, due 48 hours after it ended in Berlin, the first weekly baseline on the
     * Monday after next.
     *
     * @param array{int, int, int, int, int}|null $streaks Check-in current and longest, under-limit
     *                                                     current and longest, freezes held.
     */
    public static function progress(
        int $userNumber = 1,
        ?array $streaks = null,
        int $totalXp = 0,
        int $level = 0,
        string $nextDay = '2026-11-17',
        string $finalizeDueAt = '2026-11-19 23:00:00',
        string $baselineDueAt = '2026-11-29 23:00:00',
    ): ProgressRecord {
        return new ProgressRecord(
            self::userId($userNumber),
            new UserProgress($streaks === null ? StreakState::initial() : new StreakState(...$streaks), $totalXp),
            $level,
            self::date($nextDay),
            self::time($finalizeDueAt),
            self::time($baselineDueAt),
        );
    }

    /** An entry of the XP ledger of user $userNumber with the id uuid($number). */
    public static function xpEvent(
        int $number,
        int $userNumber = 1,
        string $date = '2026-11-17',
        XpSource $source = XpSource::CheckIn,
        int $amount = 10,
        ?int $detail = null,
        string $createdAt = '2026-11-20 23:00:30',
    ): XpEvent {
        return new XpEvent(self::uuid($number), self::userId($userNumber), self::date($date), $source, $amount, $detail, self::time($createdAt));
    }

    /** What the plan of user $userNumber says from that day on. Without a goal: reduce to 5. */
    public static function planTerms(
        int $userNumber,
        string $validFrom,
        ?Goal $goal = null,
        TaperSpeed $taperSpeed = TaperSpeed::Normal,
        string $rulesVersion = 'v1',
    ): PlanTerms {
        return new PlanTerms(self::userId($userNumber), self::date($validFrom), $goal ?? Goal::reduceTo(5), $taperSpeed, $rulesVersion);
    }

    public static function zoneChange(string $validFrom, string $zone = 'Europe/Berlin'): ZoneChange
    {
        return new ZoneChange(self::date($validFrom), TimeZone::fromInput($zone));
    }

    /**
     * A friend request of one user to another, or their friendship when $acceptedAt is given.
     */
    public static function friendship(int $requester, int $recipient, string $requestedAt = '2026-11-15 10:00:00', ?string $acceptedAt = null): Friendship
    {
        return new Friendship(
            self::userId($requester),
            self::userId($recipient),
            self::time($requestedAt),
            $acceptedAt === null ? null : self::time($acceptedAt),
        );
    }

    public static function block(int $blocker, int $blocked, string $blockedAt = '2026-11-16 09:00:00'): Block
    {
        return new Block(self::userId($blocker), self::userId($blocked), self::time($blockedAt));
    }

    /**
     * The duel with the id uuid($number): user $challenger has challenged user $opponent. A user
     * number of 0 stands for an account that was deleted. Without anything else it is a proposal
     * of 7 days, made at noon of the 17th and open for 72 hours.
     */
    public static function duel(
        int $number,
        int $challenger = 1,
        int $opponent = 2,
        int $periodDays = 7,
        DuelStatus $status = DuelStatus::Proposed,
        string $createdAt = '2026-11-17 12:00:00',
        ?string $answeredAt = null,
        ?string $challengerStart = null,
        ?string $opponentStart = null,
        ?DuelDecision $decision = null,
        ?string $expiresAt = null,
    ): Duel {
        return new Duel(
            self::uuid($number),
            $challenger === 0 ? null : self::userId($challenger),
            $opponent === 0 ? null : self::userId($opponent),
            $periodDays,
            $status,
            self::time($createdAt),
            $expiresAt === null ? self::time($createdAt)->modify('+72 hours') : self::time($expiresAt),
            $answeredAt === null ? null : self::time($answeredAt),
            $challengerStart === null ? null : self::date($challengerStart),
            $opponentStart === null ? null : self::date($opponentStart),
            $decision,
        );
    }

    /**
     * How a duel was decided. Without anything else: the challenger won with 25 % against 0 %,
     * both over 7 days against a baseline of 12.0.
     */
    public static function decision(
        DuelWinner $winner = DuelWinner::Challenger,
        ?SideResult $challenger = new SideResult(120, 630, 840, 1, 7, 100),
        ?SideResult $opponent = new SideResult(120, 840, 840, 0, 5, 20),
        string $decidedAt = '2026-11-27 23:00:40',
        string $rulesVersion = 'v1',
    ): DuelDecision {
        return new DuelDecision(self::time($decidedAt), $winner, $challenger, $opponent, $rulesVersion);
    }
}
