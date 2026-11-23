<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Privacy;

use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use SmokeDetector\Application\Privacy\ExportAccount;
use SmokeDetector\Application\Privacy\UserDataRegistry;
use SmokeDetector\Application\TransactionRunner;
use SmokeDetector\Bootstrap\ContainerFactory;
use SmokeDetector\Infrastructure\Clock\FrozenClock;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\Privacy\DatabaseContents;
use SmokeDetector\Tests\Support\Privacy\UserDataFixture;
use SmokeDetector\Tests\Support\TestData;

/**
 * The exporters and erasers of the real user data registry against the real database
 * (HLD §4.5 "Account", FR-ACC-07, FR-ACC-08).
 *
 * Two users have rows in every table (UserDataFixture). Whatever is done for the first must leave
 * the second alone.
 */
final class UserDataTablesTest extends IntegrationTestCase
{
    /**
     * Where each stored column of a user ends up in the export. "*" stands for every entry of a list.
     * A column that is added by a later migration must be added here or to NOT_EXPORTED.
     */
    private const array EXPORTED = [
        'users.id' => 'profile.id',
        'users.email' => 'profile.email',
        'users.display_name' => 'profile.displayName',
        'users.avatar_key' => 'profile.avatar',
        'users.locale' => 'profile.locale',
        'users.timezone' => 'profile.timeZone',
        'users.email_verified_at' => 'profile.emailVerifiedAt',
        'users.created_at' => 'profile.createdAt',
        'consents.type' => 'consents.*.type',
        'consents.version' => 'consents.*.version',
        'consents.granted_at' => 'consents.*.grantedAt',
        'plans.goal_mode' => 'plan.goalMode',
        'plans.target' => 'plan.target',
        'plans.taper_speed' => 'plan.taperSpeed',
        'plans.default_product' => 'plan.defaultProduct',
        'plans.start_date' => 'plan.startDate',
        'plans.rules_version' => 'plan.rulesVersion',
        'baseline_history.valid_from' => 'baselineHistory.*.validFrom',
        'baseline_history.value' => 'baselineHistory.*.value',
        'baseline_history.source' => 'baselineHistory.*.source',
        'baseline_history.w_mean' => 'baselineHistory.*.weekMean',
        'baseline_history.m_mean' => 'baselineHistory.*.monthMean',
        'baseline_history.w_days' => 'baselineHistory.*.weekDays',
        'baseline_history.m_days' => 'baselineHistory.*.monthDays',
        'baseline_history.rules_version' => 'baselineHistory.*.rulesVersion',
        'plan_history.valid_from' => 'planHistory.*.validFrom',
        'plan_history.goal_mode' => 'planHistory.*.goalMode',
        'plan_history.target' => 'planHistory.*.target',
        'plan_history.taper_speed' => 'planHistory.*.taperSpeed',
        'plan_history.rules_version' => 'planHistory.*.rulesVersion',
        'timezone_history.valid_from' => 'timeZoneHistory.*.validFrom',
        'timezone_history.timezone' => 'timeZoneHistory.*.timeZone',
        'smoke_logs.id' => 'smokeLogs.*.id',
        'smoke_logs.smoked_at' => 'smokeLogs.*.smokedAt',
        'smoke_logs.local_date' => 'smokeLogs.*.localDate',
        'smoke_logs.product_type' => 'smokeLogs.*.productType',
        'smoke_logs.count' => 'smokeLogs.*.count',
        'smoke_logs.created_at' => 'smokeLogs.*.createdAt',
        'smoke_logs.updated_at' => 'smokeLogs.*.updatedAt',
        'smoke_logs.deleted_at' => 'smokeLogs.*.deletedAt',
        'day_summaries.local_date' => 'days.*.date',
        'day_summaries.count' => 'days.*.count',
        'day_summaries.checked_in_at' => 'days.*.checkedInAt',
        'day_summaries.daily_limit' => 'days.*.dailyLimit',
        'day_summaries.xp' => 'days.*.xp',
        'day_summaries.events' => 'days.*.events',
        'day_summaries.freeze_used' => 'days.*.freezeUsed',
        'day_summaries.finalized_at' => 'days.*.finalizedAt',
        'day_summaries.rules_version' => 'days.*.rulesVersion',
        'user_progress.check_in_current' => 'progress.checkInStreak',
        'user_progress.check_in_longest' => 'progress.longestCheckInStreak',
        'user_progress.under_limit_current' => 'progress.underLimitStreak',
        'user_progress.under_limit_longest' => 'progress.longestUnderLimitStreak',
        'user_progress.freezes_held' => 'progress.freezesHeld',
        'user_progress.xp_total' => 'progress.xpTotal',
        'user_progress.level' => 'progress.level',
        'user_progress.next_day' => 'progress.nextDayToFinalize',
        'xp_events.local_date' => 'xpEvents.*.date',
        'xp_events.source' => 'xpEvents.*.source',
        'xp_events.amount' => 'xpEvents.*.amount',
        'xp_events.detail' => 'xpEvents.*.detail',
        'xp_events.created_at' => 'xpEvents.*.createdAt',
        'refresh_tokens.id' => 'sessions.*.session',
        'refresh_tokens.device_label' => 'sessions.*.deviceLabel',
        'refresh_tokens.created_at' => 'sessions.*.createdAt',
        'refresh_tokens.rotated_at' => 'sessions.*.lastRefreshedAt',
        'refresh_tokens.expires_at' => 'sessions.*.expiresAt',
        'refresh_tokens.revoked_at' => 'sessions.*.revokedAt',
        'one_time_tokens.purpose' => 'mailLinks.*.purpose',
        'one_time_tokens.expires_at' => 'mailLinks.*.expiresAt',
        'one_time_tokens.used_at' => 'mailLinks.*.usedAt',
        'mail_outbox.template' => 'queuedMails.*.template',
        'mail_outbox.created_at' => 'queuedMails.*.createdAt',
        'mail_outbox.attempts' => 'queuedMails.*.attempts',
        'invite_codes.code' => 'inviteCode.code',
        'invite_codes.created_at' => 'inviteCode.createdAt',
        // The other user of the pair, whichever of the two columns holds them.
        'friendships.user_a' => 'friends.*.userId',
        'friendships.user_b' => 'friends.*.userId',
        'friendships.requested_by' => 'friends.*.requestedByMe',
        'friendships.requested_at' => 'friends.*.requestedAt',
        'friendships.accepted_at' => 'friends.*.acceptedAt',
        'blocks.blocked_id' => 'blockedUsers.*.userId',
        'blocks.created_at' => 'blockedUsers.*.blockedAt',
        'duels.id' => 'duels.*.id',
        // Whichever of the two the user is not. Which one the user is: duels.*.role.
        'duels.challenger_id' => 'duels.*.opponentId',
        'duels.opponent_id' => 'duels.*.opponentId',
        'duels.period_days' => 'duels.*.periodDays',
        'duels.status' => 'duels.*.status',
        'duels.created_at' => 'duels.*.createdAt',
        'duels.expires_at' => 'duels.*.expiresAt',
        'duels.answered_at' => 'duels.*.answeredAt',
        // The user's own first day and the user's own side of the result: the columns of the
        // challenger for a duel the user proposed, the columns of the opponent otherwise. The other
        // side is the other user's data and in nobody's export but theirs.
        'duels.challenger_start' => 'duels.*.startsOn',
        'duels.opponent_start' => 'duels.*.startsOn',
        'duels.decided_at' => 'duels.*.decidedAt',
        'duels.winner' => 'duels.*.outcome',
        'duels.challenger_b0_tenths' => 'duels.*.result.baseline',
        'duels.challenger_actual_tenths' => 'duels.*.result.actual',
        'duels.challenger_expected_tenths' => 'duels.*.result.expected',
        'duels.challenger_smoke_free_days' => 'duels.*.result.smokeFreeDays',
        'duels.challenger_check_ins' => 'duels.*.result.checkIns',
        'duels.challenger_xp' => 'duels.*.result.xp',
        'duels.opponent_b0_tenths' => 'duels.*.result.baseline',
        'duels.opponent_actual_tenths' => 'duels.*.result.actual',
        'duels.opponent_expected_tenths' => 'duels.*.result.expected',
        'duels.opponent_smoke_free_days' => 'duels.*.result.smokeFreeDays',
        'duels.opponent_check_ins' => 'duels.*.result.checkIns',
        'duels.opponent_xp' => 'duels.*.result.xp',
        'duels.rules_version' => 'duels.*.rulesVersion',
        'league_results.period_type' => 'leagueResults.*.type',
        'league_results.period_key' => 'leagueResults.*.period',
        'league_results.b0_tenths' => 'leagueResults.*.baseline',
        'league_results.actual_tenths' => 'leagueResults.*.actual',
        'league_results.expected_tenths' => 'leagueResults.*.expected',
        'league_results.smoke_free_days' => 'leagueResults.*.smokeFreeDays',
        'league_results.check_ins' => 'leagueResults.*.checkIns',
        'league_results.counted_days' => 'leagueResults.*.countedDays',
        'league_results.rules_version' => 'leagueResults.*.rulesVersion',
        'league_results.scored_at' => 'leagueResults.*.scoredAt',
        'league_results.rank' => 'leagueResults.*.rank',
        'league_results.participants' => 'leagueResults.*.participants',
        'league_results.xp' => 'leagueResults.*.xp',
        'league_results.ranked_at' => 'leagueResults.*.rankedAt',
        'user_badges.badge_key' => 'badges.*.key',
        'user_badges.awarded_at' => 'badges.*.awardedAt',
        'user_milestones.milestone_key' => 'healthMilestones.*.key',
        'user_milestones.reached_at' => 'healthMilestones.*.reachedAt',
        'user_settings.milestone_sharing' => 'settings.milestoneSharing',
    ];

    /** Stored columns that are deliberately not in the export, and why. */
    private const array NOT_EXPORTED = [
        'users.password_hash' => 'A secret. It says nothing to the user and would help whoever gets hold of the export.',
        'consents.user_id' => 'The user\'s own id, which is profile.id.',
        'plans.user_id' => 'The user\'s own id, which is profile.id.',
        'baseline_history.user_id' => 'The user\'s own id, which is profile.id.',
        'refresh_tokens.user_id' => 'The user\'s own id, which is profile.id.',
        'plan_history.user_id' => 'The user\'s own id, which is profile.id.',
        'timezone_history.user_id' => 'The user\'s own id, which is profile.id.',
        'smoke_logs.user_id' => 'The user\'s own id, which is profile.id.',
        'day_summaries.user_id' => 'The user\'s own id, which is profile.id.',
        'user_progress.user_id' => 'The user\'s own id, which is profile.id.',
        'user_progress.finalize_due_at' => 'When the cron job looks at the user next: its schedule. It follows from progress.nextDayToFinalize and the time zone.',
        'user_progress.baseline_due_at' => 'When the cron job calculates the next weekly baseline: its schedule, the user\'s next Monday.',
        'xp_events.id' => 'An internal row number without meaning outside the database.',
        'xp_events.user_id' => 'The user\'s own id, which is profile.id.',
        'users.token_version' => 'How often the password was reset: the number an access token must carry to be accepted. Part of the credentials, like the password hash.',
        'refresh_tokens.key_hash' => 'A secret: the hash of the key that every token of the login carries.',
        'refresh_tokens.token_hash' => 'A secret: the hash of a token that may still be valid.',
        'refresh_tokens.generation' => 'How often the token of the login was exchanged: a counter the secret of the token is derived from. Part of the credentials. When it last happened is sessions.*.lastRefreshedAt.',
        'one_time_tokens.user_id' => 'The user\'s own id, which is profile.id.',
        'one_time_tokens.id' => 'An internal row number without meaning outside the database.',
        'one_time_tokens.token_hash' => 'A secret: the hash of a link that may still be valid.',
        'one_time_tokens.failed_attempts' => 'A security counter, like the rate limits: how many wrong passwords were tried with a confirmation link. It protects the link and says nothing the user can use.',
        'mail_outbox.id' => 'An internal row number without meaning outside the database.',
        'mail_outbox.user_id' => 'The user\'s own id, which is profile.id.',
        'mail_outbox.locale' => 'The language the mail will be written in: the language of the account when the mail was queued, which is profile.locale.',
        'mail_outbox.recipient' => 'The address the mail goes to: the address of the account, which is profile.email. The export shows an address in one place only.',
        'mail_outbox.sealed_values' => 'A secret: the links of the mail, encrypted. A link carries a token that may still be valid.',
        'mail_outbox.not_before' => 'When the sender tries next: the schedule of the cron job, which says nothing about the user.',
        'invite_codes.user_id' => 'The user\'s own id, which is profile.id.',
        'league_results.user_id' => 'The user\'s own id, which is profile.id.',
        'league_results.check_at' => 'When the cron job looks at the result next, to rank it: its schedule, which says nothing about the user.',
        'user_badges.user_id' => 'The user\'s own id, which is profile.id.',
        'user_milestones.user_id' => 'The user\'s own id, which is profile.id.',
        'user_settings.user_id' => 'The user\'s own id, which is profile.id.',
        'blocks.blocker_id' => 'The user\'s own id, in the blocks the export lists. A block by somebody else against the user is not exported: nobody learns who has blocked them.',
    ];

    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->container = (new ContainerFactory())->create($this->settings(), [
            ClockInterface::class => FrozenClock::at('2026-11-17 12:00:00'),
        ]);

        $sql = $this->service(Sql::class);
        foreach ([1 => 'anna@example.org', 2 => 'bob@example.org'] as $number => $email) {
            $user = TestData::user($number, $email, 'hash-of-' . $number, displayName: "Name {$number}");
            $this->sql()->execute(
                'INSERT INTO users (id, email, password_hash, display_name, avatar_key, locale, timezone, email_verified_at, created_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$user->id->value, $email, $user->passwordHash, $user->displayName->value, 'avatar-03', 'de', 'Europe/Berlin', '2026-11-02 08:00:00', '2026-11-01 07:00:00'],
            );
            (new UserDataFixture($sql))->fill($user->id, $number);
            // Three other accounts for the rows two users share: 11, 12, 13 for user 1 and 21, 22, 23 for user 2.
            foreach ([1, 2, 3] as $other) {
                $this->sql()->execute(
                    'INSERT INTO users (id, email, password_hash, display_name, avatar_key, locale, timezone, email_verified_at, created_at) '
                    . "VALUES (?, ?, 'x', ?, 'avatar-05', 'de', 'Europe/Berlin', '2026-11-02 08:00:00', '2026-11-01 07:00:00')",
                    [TestData::uuid($number * 10 + $other), "other-{$number}-{$other}@example.org", "Other {$number}{$other}"],
                );
            }
            (new UserDataFixture($sql))->fillShared($user->id, $number, TestData::userId($number * 10 + 1), TestData::userId($number * 10 + 2), TestData::userId($number * 10 + 3));
        }
    }

    public function test_FR_ACC_07_the_export_holds_what_every_table_stores_about_the_user(): void
    {
        $export = $this->service(TransactionRunner::class)->run(
            fn (): array => $this->service(UserDataRegistry::class)->export(TestData::userId(1)),
        );

        self::assertSame(
            [
                'consents' => [
                    ['type' => 'age18', 'version' => 'age-v1', 'grantedAt' => '2026-11-01T08:00:00Z'],
                    ['type' => 'art9', 'version' => 'art9-v2', 'grantedAt' => '2026-11-01T08:00:00Z'],
                    ['type' => 'privacy', 'version' => 'privacy-v3', 'grantedAt' => '2026-11-01T08:00:01Z'],
                ],
                'plan' => [
                    'goalMode' => 'reduce', 'target' => 5, 'taperSpeed' => 'gentle', 'defaultProduct' => 'rolled',
                    'startDate' => '2026-11-02', 'rulesVersion' => 'v1',
                ],
                'planHistory' => [
                    ['validFrom' => '2026-11-02', 'goalMode' => 'quit', 'target' => 0, 'taperSpeed' => 'fast', 'rulesVersion' => 'v1'],
                    ['validFrom' => '2026-11-12', 'goalMode' => 'reduce', 'target' => 5, 'taperSpeed' => 'gentle', 'rulesVersion' => 'v1'],
                ],
                'baselineHistory' => [
                    ['validFrom' => '2026-11-02', 'value' => 12.0, 'source' => 'onboarding', 'weekMean' => null, 'monthMean' => null, 'weekDays' => null, 'monthDays' => null, 'rulesVersion' => 'v1'],
                    ['validFrom' => '2026-11-09', 'value' => 9.8, 'source' => 'weekly', 'weekMean' => 9.5, 'monthMean' => 10.25, 'weekDays' => 6, 'monthDays' => 7, 'rulesVersion' => 'v1'],
                    ['validFrom' => '2026-11-16', 'value' => 9.8, 'source' => 'unchanged_low_data', 'weekMean' => null, 'monthMean' => 9.75, 'weekDays' => 0, 'monthDays' => 9, 'rulesVersion' => 'v1'],
                ],
                'timeZoneHistory' => [
                    ['validFrom' => '2026-11-02', 'timeZone' => 'Europe/Lisbon'],
                    ['validFrom' => '2026-11-12', 'timeZone' => 'Europe/Berlin'],
                ],
                'smokeLogs' => [
                    // In the order they were smoked. A deleted log is still stored, and says so.
                    [
                        'id' => TestData::uuid(1008), 'smokedAt' => '2026-11-17T07:15:00Z', 'localDate' => '2026-11-17', 'productType' => 'cigarette', 'count' => 1,
                        'createdAt' => '2026-11-17T07:15:04Z', 'updatedAt' => '2026-11-17T07:15:04Z', 'deletedAt' => null,
                    ],
                    [
                        'id' => TestData::uuid(1009), 'smokedAt' => '2026-11-17T23:30:00Z', 'localDate' => '2026-11-18', 'productType' => 'vape', 'count' => 3,
                        'createdAt' => '2026-11-18T06:00:00Z', 'updatedAt' => '2026-11-18T06:05:00Z', 'deletedAt' => '2026-11-18T06:05:00Z',
                    ],
                ],
                'days' => [
                    [
                        'date' => '2026-11-10', 'count' => 6, 'checkedInAt' => '2026-11-10T21:00:00Z', 'dailyLimit' => 9, 'xp' => 80,
                        'events' => [['type' => 'checked_in'], ['type' => 'under_limit_day', 'value' => 1], ['type' => 'streak_milestone', 'value' => 7]],
                        'freezeUsed' => false, 'finalizedAt' => '2026-11-12T23:00:20Z', 'rulesVersion' => 'v1',
                    ],
                    // A day that is not final yet has nothing of what finalization adds.
                    [
                        'date' => '2026-11-17', 'count' => 1, 'checkedInAt' => null, 'dailyLimit' => null, 'xp' => null,
                        'events' => null, 'freezeUsed' => null, 'finalizedAt' => null, 'rulesVersion' => null,
                    ],
                ],
                'progress' => [
                    'checkInStreak' => 3, 'longestCheckInStreak' => 5, 'underLimitStreak' => 1, 'longestUnderLimitStreak' => 4, 'freezesHeld' => 1,
                    'xpTotal' => 250, 'level' => 2, 'nextDayToFinalize' => '2026-11-15',
                ],
                'xpEvents' => [
                    ['date' => '2026-11-10', 'source' => 'check_in', 'amount' => 10, 'detail' => null, 'createdAt' => '2026-11-12T23:00:20Z'],
                    ['date' => '2026-11-10', 'source' => 'streak_milestone', 'amount' => 50, 'detail' => 7, 'createdAt' => '2026-11-12T23:00:20Z'],
                ],
                'sessions' => [
                    // One entry per login, however often its token was exchanged.
                    ['session' => TestData::uuid(1001), 'deviceLabel' => 'Phone 1', 'createdAt' => '2026-11-10T09:00:00Z', 'lastRefreshedAt' => '2026-11-12T07:30:00Z', 'expiresAt' => '2027-01-11T07:30:00Z', 'revokedAt' => null],
                    ['session' => TestData::uuid(1003), 'deviceLabel' => null, 'createdAt' => '2026-11-13T20:00:00Z', 'lastRefreshedAt' => null, 'expiresAt' => '2027-01-12T20:00:00Z', 'revokedAt' => '2026-11-13T21:00:00Z'],
                ],
                'mailLinks' => [
                    ['purpose' => 'verify_email', 'expiresAt' => '2026-11-02T08:00:00Z', 'usedAt' => '2026-11-01T08:05:00Z'],
                    ['purpose' => 'reset_password', 'expiresAt' => '2026-11-17T13:00:00Z', 'usedAt' => null],
                ],
                'queuedMails' => [
                    // Which mail waits, since when, and how often the mail server refused it. Not where
                    // it goes (the profile says that) and never what is in it.
                    ['template' => 'confirm_email', 'createdAt' => '2026-11-17T11:58:00Z', 'attempts' => 0],
                    ['template' => 'reset_password', 'createdAt' => '2026-11-17T11:59:00Z', 'attempts' => 2],
                ],
                'inviteCode' => ['code' => 'K7M2QX9RT2', 'createdAt' => '2026-11-12T08:00:00Z'],
                // Of the other user the id and nothing else, oldest request first.
                'friends' => [
                    ['userId' => TestData::uuid(11), 'status' => 'accepted', 'requestedByMe' => true, 'requestedAt' => '2026-11-10T10:00:00Z', 'acceptedAt' => '2026-11-11T09:00:00Z'],
                    ['userId' => TestData::uuid(12), 'status' => 'pending', 'requestedByMe' => false, 'requestedAt' => '2026-11-15T10:00:00Z', 'acceptedAt' => null],
                ],
                // Whom the user has blocked. Not that user 13 has blocked the user too.
                'blockedUsers' => [
                    ['userId' => TestData::uuid(13), 'blockedAt' => '2026-11-16T09:00:00Z'],
                ],
                // The user's own side of each duel, oldest first. Of the opponent: the id. Not that
                // user 11 started a day later, smoked 66.5 against a baseline of 9.5 and got 20 XP.
                'duels' => [
                    [
                        'id' => TestData::uuid(1020), 'status' => 'completed', 'periodDays' => 7, 'role' => 'challenger', 'opponentId' => TestData::uuid(11),
                        'createdAt' => '2026-10-20T08:00:00Z', 'expiresAt' => '2026-10-23T08:00:00Z', 'answeredAt' => '2026-10-20T09:00:00Z',
                        'startsOn' => '2026-10-21', 'decidedAt' => '2026-10-30T23:00:40Z', 'outcome' => 'won',
                        'result' => ['baseline' => 12.0, 'actual' => 63.0, 'expected' => 84.0, 'smokeFreeDays' => 1, 'checkIns' => 7, 'xp' => 100],
                        'rulesVersion' => 'v1',
                    ],
                    [
                        'id' => TestData::uuid(1021), 'status' => 'declined', 'periodDays' => 30, 'role' => 'opponent', 'opponentId' => TestData::uuid(12),
                        'createdAt' => '2026-11-14T08:00:00Z', 'expiresAt' => '2026-11-17T08:00:00Z', 'answeredAt' => '2026-11-14T10:00:00Z',
                        'startsOn' => null, 'decidedAt' => null, 'outcome' => null, 'result' => null, 'rulesVersion' => null,
                    ],
                ],
                // The user's own final results, oldest first, exactly as they were scored.
                'leagueResults' => [
                    [
                        'type' => 'month', 'period' => '2026-10', 'baseline' => 9.5, 'actual' => 2850.0, 'expected' => 2945.0, 'smokeFreeDays' => 0, 'checkIns' => 30,
                        'countedDays' => 31, 'rulesVersion' => 'v1', 'scoredAt' => '2026-11-02T23:00:20Z', 'rank' => null, 'participants' => null, 'xp' => null, 'rankedAt' => null,
                    ],
                    [
                        'type' => 'week', 'period' => '2026-W45', 'baseline' => 12.0, 'actual' => 63.0, 'expected' => 84.0, 'smokeFreeDays' => 1, 'checkIns' => 6,
                        'countedDays' => 7, 'rulesVersion' => 'v1', 'scoredAt' => '2026-11-10T23:00:20Z', 'rank' => 2, 'participants' => 4, 'xp' => 100, 'rankedAt' => '2026-11-11T23:01:05Z',
                    ],
                ],
                // The user's badges with their time, in the order of the badge cases.
                'badges' => [
                    ['key' => 'first_check_in', 'awardedAt' => '2026-11-04T23:00:20Z'],
                    ['key' => 'check_in_streak_7', 'awardedAt' => '2026-11-12T23:00:20Z'],
                ],
                // The health milestones reached for good, with their first moment, in the order of the cases.
                'healthMilestones' => [
                    ['key' => 'heart_rate', 'reachedAt' => '2026-11-01T08:20:00Z'],
                    ['key' => 'circulation', 'reachedAt' => '2026-11-15T08:00:00Z'],
                ],
                'settings' => ['milestoneSharing' => 'all'],
            ],
            $export,
        );
    }

    public function test_FR_ACC_07_a_user_without_rows_exports_empty_lists_and_no_plan(): void
    {
        $this->sql()->execute(
            'INSERT INTO users (id, email, password_hash, display_name, avatar_key, locale, timezone, email_verified_at, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [TestData::uuid(3), 'carol@example.org', 'x', 'Carol', 'avatar-03', 'de', 'Europe/Berlin', '2026-11-02 08:00:00', '2026-11-01 07:00:00'],
        );

        $export = $this->service(UserDataRegistry::class)->export(TestData::userId(3));

        // The lists are empty. What a user has once or not at all (plan, progress) is absent. The
        // settings are there with the defaults: who never chose has them.
        self::assertSame(
            [
                'consents' => [], 'planHistory' => [], 'baselineHistory' => [], 'timeZoneHistory' => [], 'smokeLogs' => [], 'days' => [], 'xpEvents' => [],
                'sessions' => [], 'mailLinks' => [], 'queuedMails' => [], 'friends' => [], 'blockedUsers' => [], 'duels' => [], 'leagueResults' => [], 'badges' => [],
                'healthMilestones' => [], 'settings' => ['milestoneSharing' => 'from_two_weeks'],
            ],
            $export,
        );
    }

    public function test_FR_ACC_07_every_stored_column_about_a_user_is_exported_or_deliberately_left_out(): void
    {
        $tables = ['users', ...$this->service(UserDataRegistry::class)->tables()];

        $stored = [];
        foreach ($tables as $table) {
            foreach ($this->sql()->column('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]) as $column) {
                self::assertIsString($column);
                $stored[] = "{$table}.{$column}";
            }
        }
        $accountedFor = [...array_keys(self::EXPORTED), ...array_keys(self::NOT_EXPORTED)];

        self::assertSame([], array_values(array_diff($stored, $accountedFor)), 'Stored columns that are neither exported nor deliberately left out.');
        self::assertSame([], array_values(array_diff($accountedFor, $stored)), 'Columns that are listed here but do not exist (any more).');
        self::assertSame([], array_values(array_intersect(array_keys(self::EXPORTED), array_keys(self::NOT_EXPORTED))));

        // And the members named above are really in the document of a user who has rows everywhere.
        $export = $this->service(ExportAccount::class)->execute(TestData::userId(1));
        foreach (self::EXPORTED as $column => $path) {
            self::assertTrue(self::has($export, explode('.', $path)), "{$column} should be exported as {$path}.");
        }
    }

    public function test_NFR_05_the_export_contains_no_password_hash_no_token_and_no_token_hash(): void
    {
        $json = json_encode($this->service(ExportAccount::class)->execute(TestData::userId(1)), JSON_THROW_ON_ERROR);

        foreach (['hash-of-1', ...UserDataFixture::secrets(1)] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertDoesNotMatchRegularExpression('/"[^"]*(hash|password|token)[^"]*":/i', $json, 'No member is named like a secret.');
        // The address a queued mail goes to is in the export once, as the profile's.
        self::assertStringNotContainsString('mailbox-1@example.org', $json);
    }

    public function test_FR_ACC_07_the_export_of_one_user_contains_nothing_of_another(): void
    {
        $json = json_encode($this->service(ExportAccount::class)->execute(TestData::userId(1)), JSON_THROW_ON_ERROR);

        foreach ([TestData::uuid(2), 'bob@example.org', 'Name 2', 'Phone 2', TestData::uuid(2101), TestData::uuid(2102), TestData::uuid(2008), TestData::uuid(2009), 'mailbox-2@example.org', 'K7M2QX9RT3', TestData::uuid(21), TestData::uuid(22), TestData::uuid(23), 'Other'] as $bobs) {
            self::assertStringNotContainsString($bobs, $json);
        }
        self::assertStringContainsString('anna@example.org', $json);
    }

    public function test_FR_ACC_07_exporting_changes_nothing_in_any_table(): void
    {
        $before = DatabaseContents::all($this->sql(), ['rate_limits']);

        $this->service(ExportAccount::class)->execute(TestData::userId(1));

        self::assertSame($before, DatabaseContents::all($this->sql(), ['rate_limits']));
    }

    public function test_the_fixture_fills_every_registered_table_for_both_users(): void
    {
        // Otherwise the tests below would pass for a table they never looked at.
        foreach ($this->service(UserDataRegistry::class)->tables() as $table) {
            foreach ([1, 2] as $number) {
                self::assertNotSame(
                    [],
                    $this->rowsOfUser($number)[$table] ?? [],
                    "UserDataFixture stores no row in `{$table}` for user {$number}. Add one, so that export and erasure of the table are tested.",
                );
            }
        }
    }

    public function test_FR_ACC_08_the_erasers_alone_remove_every_row_of_the_user_from_every_table(): void
    {
        // Without deleting the account: so it is the erasers that do the work here, not the
        // foreign keys' ON DELETE CASCADE.
        $this->service(TransactionRunner::class)->run(
            fn () => $this->service(UserDataRegistry::class)->erase(TestData::userId(1)),
        );

        self::assertSame(['users' => 1], DatabaseContents::rowsMentioning($this->sql(), [TestData::uuid(1)]));
    }

    public function test_FR_ACC_08_of_a_duel_the_side_of_the_erased_user_goes_and_the_other_side_stays(): void
    {
        // Next to the completed and the declined duel of the fixture: a proposal of user 1 to user 11,
        // and a running duel user 12 proposed.
        $this->sql()->execute(
            'INSERT INTO duels (id, challenger_id, opponent_id, period_days, status, created_at, expires_at) VALUES (?, ?, ?, 7, \'proposed\', \'2026-11-17 08:00:00\', \'2026-11-20 08:00:00\')',
            [TestData::uuid(1030), TestData::uuid(1), TestData::uuid(11)],
        );
        $this->sql()->execute(
            'INSERT INTO duels (id, challenger_id, opponent_id, period_days, status, created_at, expires_at, answered_at, challenger_start, opponent_start) '
            . "VALUES (?, ?, ?, 30, 'active', '2026-11-10 08:00:00', '2026-11-13 08:00:00', '2026-11-10 09:00:00', '2026-11-11', '2026-11-12')",
            [TestData::uuid(1031), TestData::uuid(12), TestData::uuid(1)],
        );
        $duels = fn (): array => $this->sql()->rows(
            'SELECT id, status, challenger_id, opponent_id, challenger_start, opponent_start, winner, challenger_b0_tenths, challenger_xp, opponent_b0_tenths, opponent_xp '
            . 'FROM duels WHERE id IN (?, ?, ?, ?) ORDER BY id',
            [TestData::uuid(1020), TestData::uuid(1021), TestData::uuid(1030), TestData::uuid(1031)],
        );
        $othersBefore = $this->sql()->rows('SELECT * FROM duels WHERE id NOT IN (?, ?, ?, ?) ORDER BY id', [TestData::uuid(1020), TestData::uuid(1021), TestData::uuid(1030), TestData::uuid(1031)]);

        $this->service(TransactionRunner::class)->run(
            fn () => $this->service(UserDataRegistry::class)->erase(TestData::userId(1)),
        );

        self::assertSame(
            [
                // Decided: the other user keeps the duel and the own side. Who won stays, the scores of the erased side do not.
                [
                    'id' => TestData::uuid(1020), 'status' => 'completed', 'challenger_id' => null, 'opponent_id' => TestData::uuid(11),
                    'challenger_start' => null, 'opponent_start' => '2026-10-22', 'winner' => 'challenger',
                    'challenger_b0_tenths' => null, 'challenger_xp' => null, 'opponent_b0_tenths' => 95, 'opponent_xp' => 20,
                ],
                [
                    'id' => TestData::uuid(1021), 'status' => 'declined', 'challenger_id' => TestData::uuid(12), 'opponent_id' => null,
                    'challenger_start' => null, 'opponent_start' => null, 'winner' => null,
                    'challenger_b0_tenths' => null, 'challenger_xp' => null, 'opponent_b0_tenths' => null, 'opponent_xp' => null,
                ],
                // The proposal is gone. The running duel is a forfeit: no winner, no XP (FR-ACC-08).
                [
                    'id' => TestData::uuid(1031), 'status' => 'forfeited', 'challenger_id' => TestData::uuid(12), 'opponent_id' => null,
                    'challenger_start' => '2026-11-11', 'opponent_start' => null, 'winner' => null,
                    'challenger_b0_tenths' => null, 'challenger_xp' => null, 'opponent_b0_tenths' => null, 'opponent_xp' => null,
                ],
            ],
            $duels(),
        );
        // No value of the erased side is left in any column of the decided duel.
        $decided = $this->sql()->row('SELECT * FROM duels WHERE id = ?', [TestData::uuid(1020)]);
        self::assertIsArray($decided);
        self::assertSame([], array_filter($decided, static fn (mixed $value, string $column): bool => str_starts_with($column, 'challenger_') && $value !== null, ARRAY_FILTER_USE_BOTH));
        self::assertSame($othersBefore, $this->sql()->rows('SELECT * FROM duels WHERE id NOT IN (?, ?, ?, ?) ORDER BY id', [TestData::uuid(1020), TestData::uuid(1021), TestData::uuid(1030), TestData::uuid(1031)]));
        self::assertSame([], $this->sql()->rows('SELECT * FROM xp_events WHERE source = ?', ['duel']));

        // When the other user goes too, nobody is left to whom the duel belongs.
        $this->service(TransactionRunner::class)->run(
            fn () => $this->service(UserDataRegistry::class)->erase(TestData::userId(11)),
        );
        self::assertSame([TestData::uuid(1021), TestData::uuid(1031)], array_column($duels(), 'id'));
    }

    public function test_FR_ACC_08_erasing_one_user_leaves_every_row_of_another_as_it_was(): void
    {
        $before = $this->rowsOfUser(2);

        $this->service(TransactionRunner::class)->run(
            fn () => $this->service(UserDataRegistry::class)->erase(TestData::userId(1)),
        );

        self::assertSame($before, $this->rowsOfUser(2));
        self::assertNotSame([], array_filter($before));
    }

    public function test_FR_ACC_08_erasing_a_user_who_has_nothing_does_nothing(): void
    {
        $before = DatabaseContents::all($this->sql());

        $this->service(TransactionRunner::class)->run(
            fn () => $this->service(UserDataRegistry::class)->erase(TestData::userId(99)),
        );

        self::assertSame($before, DatabaseContents::all($this->sql()));
    }

    /**
     * @return array<string, list<array<string, mixed>>> Table => the rows that hold the id of the user.
     */
    private function rowsOfUser(int $number): array
    {
        $rows = [];
        foreach (DatabaseContents::all($this->sql()) as $table => $all) {
            $rows[$table] = array_values(array_filter(
                $all,
                static fn (array $row): bool => in_array(TestData::uuid($number), $row, true),
            ));
        }

        return $rows;
    }

    /**
     * Whether the document has a member at the path. "*" must hold for at least one entry of a list
     * (a nullable column is filled in some rows of the fixture and empty in others).
     *
     * @param list<string> $path
     */
    private static function has(mixed $node, array $path): bool
    {
        if ($path === []) {
            return $node !== null;
        }
        if (!is_array($node)) {
            return false;
        }
        $key = array_shift($path);
        if ($key === '*') {
            foreach ($node as $entry) {
                if (self::has($entry, $path)) {
                    return true;
                }
            }

            return false;
        }

        return array_key_exists($key, $node) && self::has($node[$key], $path);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = $this->container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
