<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Privacy;

use SmokeDetector\Application\Account\Consent;
use SmokeDetector\Application\Account\ConsentType;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\Locale;
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
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoBaselineHistory;
use SmokeDetector\Infrastructure\Repository\PdoConsentRepository;
use SmokeDetector\Infrastructure\Repository\PdoMailOutbox;
use SmokeDetector\Infrastructure\Repository\PdoOneTimeTokenRepository;
use SmokeDetector\Infrastructure\Repository\PdoPlanRepository;
use SmokeDetector\Infrastructure\Repository\PdoRefreshTokenRepository;
use SmokeDetector\Tests\Support\TestData;

/**
 * Gives an account a row (or several) in EVERY table that can hold data of a user, with every
 * column filled, so that the export and deletion tests see all of them.
 *
 * A build step that adds a table with user data adds its rows here. The tests of the user data
 * registry fail when a registered table is left empty by this fixture.
 */
final readonly class UserDataFixture
{
    public function __construct(private Sql $sql)
    {
    }

    /**
     * @param int $number Makes the ids and tokens of this user's rows unique: 1 for the first user
     *                    of a test, 2 for the second, …
     */
    public function fill(UserId $userId, int $number): void
    {
        $id = static fn (int $row): string => TestData::uuid($number * 1000 + $row);

        (new PdoConsentRepository($this->sql))->add($userId, [
            new Consent(ConsentType::Age18, 'age-v1', TestData::time('2026-11-01 08:00:00')),
            new Consent(ConsentType::Art9, 'art9-v2', TestData::time('2026-11-01 08:00:00')),
            new Consent(ConsentType::Privacy, 'privacy-v3', TestData::time('2026-11-01 08:00:01')),
        ]);

// One login that was refreshed, and a second one that was logged out.
        $refreshTokens = new PdoRefreshTokenRepository($this->sql);
        $refreshTokens->add(new RefreshToken(
            $id(1), $userId, hash('sha256', "key-{$number}-a"), hash('sha256', "key-{$number}-a.secret"), DeviceLabel::fromInput("Phone {$number}"),
            TestData::time('2026-11-10 09:00:00'), TestData::time('2026-11-12 07:30:00'), TestData::time('2027-01-11 07:30:00'), null,
        ));
        $refreshTokens->add(new RefreshToken(
            $id(3), $userId, hash('sha256', "key-{$number}-c"), hash('sha256', "key-{$number}-c.secret"), null,
            TestData::time('2026-11-13 20:00:00'), null, TestData::time('2027-01-12 20:00:00'), TestData::time('2026-11-13 21:00:00'),
        ));

        $oneTimeTokens = new PdoOneTimeTokenRepository($this->sql);
        $oneTimeTokens->put(new OneTimeToken(
            $id(4), $userId, TokenPurpose::VerifyEmail, hash('sha256', "verify-{$number}"),
            TestData::time('2026-11-02 08:00:00'), TestData::time('2026-11-01 08:05:00'),
        ));
        $oneTimeTokens->put(new OneTimeToken(
            $id(5), $userId, TokenPurpose::ResetPassword, hash('sha256', "reset-{$number}"),
            TestData::time('2026-11-17 13:00:00'), null,
        ));

        // Two mails that wait to be sent: a new one, and one the mail server has refused twice.
        $outbox = new PdoMailOutbox($this->sql);
        $outbox->add(new QueuedMail(
            $id(6), $userId, MailKind::ConfirmEmail, Locale::De, EmailAddress::fromInput("mailbox-{$number}@example.org"), "sealed-links-{$number}-a",
            TestData::time('2026-11-17 11:58:00'), TestData::time('2026-11-17 11:58:00'), 0,
        ));
        $outbox->add(new QueuedMail(
            $id(7), $userId, MailKind::ResetPassword, Locale::En, EmailAddress::fromInput("mailbox-{$number}@example.org"), "sealed-links-{$number}-b",
            TestData::time('2026-11-17 11:59:00'), TestData::time('2026-11-17 12:06:00'), 2,
        ));

        (new PdoPlanRepository($this->sql))->save(new Plan(
            $userId, Goal::reduceTo(5), TaperSpeed::Gentle, ProductType::Rolled, LocalDate::fromString('2026-11-02'), 'v1',
        ));

        $baselines = new PdoBaselineHistory($this->sql);
        $baselines->append(BaselineEntry::onboarding($userId, LocalDate::fromString('2026-11-02'), Baseline::fromTenths(120), 'v1'));
        $baselines->append(new BaselineEntry(
            $userId, LocalDate::fromString('2026-11-09'), Baseline::fromTenths(98), BaselineSource::Weekly, 9.5, 10.25, 6, 7, 'v1',
        ));
        $baselines->append(new BaselineEntry(
            $userId, LocalDate::fromString('2026-11-16'), Baseline::fromTenths(98), BaselineSource::UnchangedLowData, null, 9.75, 0, 9, 'v1',
        ));

        // The tables of step 4, written as rows: the fixture must not depend on the code it helps to test.
        $user = $userId->value;
        $this->insert('plan_history', [$user, '2026-11-02', 'quit', 0, 'fast', 'v1']);
        $this->insert('plan_history', [$user, '2026-11-12', 'reduce', 5, 'gentle', 'v1']);
        $this->insert('timezone_history', [$user, '2026-11-02', 'Europe/Lisbon']);
        $this->insert('timezone_history', [$user, '2026-11-12', 'Europe/Berlin']);
        // A log of the morning, and one of late evening (the 18th in Berlin already) that was deleted.
        $this->insert('smoke_logs', [$id(8), $user, '2026-11-17 07:15:00', '2026-11-17', 'cigarette', 1, '2026-11-17 07:15:04', '2026-11-17 07:15:04', null]);
        $this->insert('smoke_logs', [$id(9), $user, '2026-11-17 23:30:00', '2026-11-18', 'vape', 3, '2026-11-18 06:00:00', '2026-11-18 06:05:00', '2026-11-18 06:05:00']);
        // A final day and one that is still open.
        $this->insert('day_summaries', [
            $user, '2026-11-10', 6, '2026-11-10 21:00:00', 9, 80,
            '[{"type":"checked_in"},{"type":"under_limit_day","value":1},{"type":"streak_milestone","value":7}]', 0, '2026-11-12 23:00:20', 'v1',
        ]);
        $this->insert('day_summaries', [$user, '2026-11-17', 1, null, null, null, null, null, null, null]);
        $this->insert('user_progress', [$user, 3, 5, 1, 4, 1, 250, 2, '2026-11-15', '2026-11-17 23:00:00', '2026-11-22 23:00:00']);
        $this->insert('xp_events', [$id(10), $user, '2026-11-10', 'check_in', 10, null, '2026-11-12 23:00:20']);
        $this->insert('xp_events', [$id(11), $user, '2026-11-10', 'streak_milestone', 50, 7, '2026-11-12 23:00:20']);
    }

    /**
     * @param list<int|string|null> $values One for every column of the table, in the order of the table.
     */
    private function insert(string $table, array $values): void
    {
        // The table names are fixed, in fill() above.
        $this->sql->execute("INSERT INTO `{$table}` VALUES (" . implode(', ', array_fill(0, count($values), '?')) . ')', $values);
    }

    /**
     * The secrets of fill(): what must never show up in an export. The tokens themselves are not
     * stored anywhere, their hashes are. Of a mail that waits to be sent, the encrypted links are a
     * secret too (they carry a token).
     *
     * @return list<string>
     */
    public static function secrets(int $number): array
    {
        $secrets = [];
        foreach (["key-{$number}-a", "key-{$number}-a.secret", "key-{$number}-c", "key-{$number}-c.secret", "verify-{$number}", "reset-{$number}"] as $token) {
            $secrets[] = $token;
            $secrets[] = hash('sha256', $token);
        }
        $secrets[] = "sealed-links-{$number}-a";
        $secrets[] = "sealed-links-{$number}-b";

        return $secrets;
    }
}
