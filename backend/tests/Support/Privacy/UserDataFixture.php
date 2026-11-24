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
            $userId, Goal::reduceTo(5), TaperSpeed::Gentle, ProductType::Rolled, LocalDate::fromString('2026-11-02'), 'v1', LocalDate::fromString('2026-10-01'),
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
        $this->fillLeagueResults($user);
        $this->fillBadges($user);
        $this->fillMilestonesAndSettings($user);
        $this->fillFeed($user, $number);
    }

    /**
     * The league results of step 5: a week that is ranked, and a month that waits for its rank.
     */
    private function fillLeagueResults(string $user): void
    {
        $this->insert('league_results', [$user, 'week', '2026-W45', 120, 630, 840, 1, 6, 7, 'v1', '2026-11-10 23:00:20', '2026-11-10 23:00:20', 2, 4, 100, '2026-11-11 23:01:05']);
        $this->insert('league_results', [$user, 'month', '2026-10', 95, 28500, 29450, 0, 30, 31, 'v1', '2026-11-02 23:00:20', '2026-11-02 23:15:20', null, null, null, null]);
    }

    /**
     * The badges of step 6, stored in another order than the badge cases have.
     */
    private function fillBadges(string $user): void
    {
        $this->insert('user_badges', [$user, 'check_in_streak_7', '2026-11-12 23:00:20']);
        $this->insert('user_badges', [$user, 'first_check_in', '2026-11-04 23:00:20']);
    }

    /**
     * The health milestones of step 6, stored in another order than the milestone cases have, and a
     * setting other than the default.
     */
    private function fillMilestonesAndSettings(string $user): void
    {
        $this->insert('user_milestones', [$user, 'circulation', '2026-11-15 08:00:00']);
        $this->insert('user_milestones', [$user, 'heart_rate', '2026-11-01 08:20:00']);
        $this->insert('user_settings', [$user, 'all']);
    }

    /**
     * The entries of the activity feed of step 6 that name nobody else: a level and a badge written by
     * one final day. The position is left to the database.
     */
    private function fillFeed(string $user, int $number): void
    {
        $this->insert('feed_events', [null, TestData::uuid($number * 1000 + 40), $user, 'level_up', '2', null, '2026-11-12 23:00:20']);
        $this->insert('feed_events', [null, TestData::uuid($number * 1000 + 41), $user, 'badge_earned', 'check_in_streak_7', null, '2026-11-12 23:00:20']);
    }

    /**
     * The tables of step 5, in which a row belongs to two users: rows between the user and three
     * other accounts, which must exist. $friend is a friend the user has asked, $asking has asked the
     * user and waits for the answer, and $blocked and the user have blocked each other.
     *
     * Use other accounts for every user of a test: then no row of one user mentions another one.
     */
    public function fillShared(UserId $userId, int $number, UserId $friend, UserId $asking, UserId $blocked): void
    {
        $user = $userId->value;
        $this->insert('invite_codes', [$user, self::inviteCode($number), '2026-11-12 08:00:00']);
        $this->insert('friendships', [...self::pair($user, $friend->value), $user, '2026-11-10 10:00:00', '2026-11-11 09:00:00']);
        $this->insert('friendships', [...self::pair($user, $asking->value), $asking->value, '2026-11-15 10:00:00', null]);
        $this->insert('blocks', [$user, $blocked->value, '2026-11-16 09:00:00']);
        $this->insert('blocks', [$blocked->value, $user, '2026-11-16 09:30:00']);
        // A duel the user proposed and won against the friend, and one the user declined.
        $this->insert('duels', [
            TestData::uuid($number * 1000 + 20), $user, $friend->value, 7, 'completed', '2026-10-20 08:00:00', '2026-10-23 08:00:00', '2026-10-20 09:00:00',
            '2026-10-21', '2026-10-22', '2026-10-30 23:00:40', 'challenger', 120, 630, 840, 1, 7, 100, 95, 665, 665, 0, 5, 20, 'v1',
        ]);
        $this->insert('duels', [
            TestData::uuid($number * 1000 + 21), $asking->value, $user, 30, 'declined', '2026-11-14 08:00:00', '2026-11-17 08:00:00', '2026-11-14 10:00:00',
            ...array_fill(0, 17, null),
        ]);
        // The feed entry of the duel the user won, naming the friend; and one of the friend, of an
        // earlier duel, naming the user.
        $this->insert('feed_events', [null, TestData::uuid($number * 1000 + 42), $user, 'duel_won', null, $friend->value, '2026-10-30 23:00:40']);
        $this->insert('feed_events', [null, TestData::uuid($number * 1000 + 43), $friend->value, 'duel_won', null, $user, '2026-10-09 23:00:40']);
        // The friend reacted to the user's entry, and the user to the friend's.
        $this->insert('feed_reactions', [TestData::uuid($number * 1000 + 42), $friend->value, 'strong', '2026-10-31 07:00:00']);
        $this->insert('feed_reactions', [TestData::uuid($number * 1000 + 43), $user, 'cheer', '2026-10-31 08:00:00']);
        // The user nudged the friend, and was delivered; the friend nudged the user, who had checked in.
        $this->insert('nudges', [$user, $friend->value, '2026-11-16', '2026-11-16 18:00:00', 1]);
        $this->insert('nudges', [$friend->value, $user, '2026-11-17', '2026-11-17 07:30:00', 0]);
    }

    /**
     * The invite code fillShared() gives the user with this number (1 to 8).
     */
    public static function inviteCode(int $number): string
    {
        return 'K7M2QX9RT' . '23456789'[$number - 1];
    }

    /**
     * @return array{string, string} The two ids as `friendships` stores them: the smaller one first.
     */
    private static function pair(string $one, string $other): array
    {
        return $one < $other ? [$one, $other] : [$other, $one];
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
