<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Http\Competition;

use DateTimeImmutable;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Competition\Duel;
use SmokeDetector\Application\Competition\DuelInvitations;
use SmokeDetector\Application\Competition\DuelStatus;
use SmokeDetector\Application\Competition\ShowDuels;
use SmokeDetector\Console\Console;
use SmokeDetector\Infrastructure\Database\Database;
use SmokeDetector\Tests\Support\Fake\BufferedOutput;
use SmokeDetector\Tests\Support\Http\ApiResponse;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\Paths;
use SmokeDetector\Tests\Support\Privacy\DatabaseContents;
use SmokeDetector\Tests\Support\TestAccounts;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Support\TestDatabase;
use Symfony\Component\Yaml\Yaml;

/**
 * Duels through the real application and database (HLD §4.8). The rules themselves are tested
 * without I/O in tests/Unit/Application/Competition.
 *
 * It is noon UTC on Tuesday, 17 November 2026, and users live in Berlin. A user who does the
 * onboarding here starts on the 17th with a baseline of 12: 6 sessions on a day are 50 %.
 */
final class DuelsTest extends HttpTestCase
{
    private const array PLAN = ['startingBaseline' => 12, 'goalMode' => 'reduce', 'target' => 5, 'taperSpeed' => 'normal', 'defaultProduct' => 'rolled'];

    private const array NOTHING_YET = ['startsOn' => null, 'endsOn' => null, 'mine' => null, 'theirs' => null, 'outcome' => null, 'xp' => null, 'provisional' => false];

    private int $logs = 0;

    public function test_FR_COMP_01_a_proposal_is_stored_and_both_see_it(): void
    {
        [$anna, $bob] = $this->twoFriends();

        $response = $this->post($anna, '/duels', ['opponentId' => $bob->id->value, 'periodDays' => 30]);

        self::assertSame(201, $response->status());
        $duel = $response->json();
        self::assertIsString($duel['id'] ?? null);
        self::assertSame(
            [
                'id' => $duel['id'], 'status' => 'proposed', 'periodDays' => 30, 'challenger' => true, 'opponent' => self::publicUser($bob, 'Bob'),
                'createdAt' => '2026-11-17T12:00:00Z', 'expiresAt' => '2026-11-20T12:00:00Z',
            ] + self::NOTHING_YET,
            $duel,
        );
        self::assertSame(['duels' => [$duel]], $this->call('GET', '/duels', headers: $this->authorization($anna))->json());
        self::assertSame(
            ['duels' => [array_replace($duel, ['challenger' => false, 'opponent' => self::publicUser($anna, 'Anna')])]],
            $this->call('GET', '/duels', headers: $this->authorization($bob))->json(),
        );
        $row = $this->sql()->row('SELECT * FROM duels');
        self::assertIsArray($row);
        self::assertSame(
            [$duel['id'], $anna->id->value, $bob->id->value, 30, 'proposed', '2026-11-17 12:00:00', '2026-11-20 12:00:00'],
            array_slice(array_values($row), 0, 7),
        );
        self::assertSame(array_fill(0, 18, null), array_slice(array_values($row), 7));
    }

    public function test_FR_COMP_02_an_accepted_duel_starts_tomorrow_and_runs_for_exactly_its_days(): void
    {
        [$anna, $bob] = $this->twoFriends();
        $id = $this->proposed($anna, $bob, 7);
        $this->clock()->advanceSeconds(3600);

        $response = $this->post($bob, '/duels/accept', ['duelId' => $id]);

        self::assertSame(200, $response->status());
        self::assertSame(
            [
                'id' => $id, 'status' => 'active', 'periodDays' => 7, 'challenger' => false, 'opponent' => self::publicUser($anna, 'Anna'),
                'createdAt' => '2026-11-17T12:00:00Z', 'expiresAt' => '2026-11-20T12:00:00Z', 'startsOn' => '2026-11-18', 'endsOn' => '2026-11-24',
                'mine' => null, 'theirs' => null, 'outcome' => null, 'xp' => null, 'provisional' => true,
            ],
            $response->json(),
        );
        self::assertSame(
            [['status' => 'active', 'answered_at' => '2026-11-17 13:00:00', 'challenger_start' => '2026-11-18', 'opponent_start' => '2026-11-18']],
            $this->sql()->rows('SELECT status, answered_at, challenger_start, opponent_start FROM duels'),
        );
    }

    public function test_FR_COMP_03_a_running_duel_shows_both_sides_in_percent_and_nothing_about_the_smoking(): void
    {
        [$anna, $bob] = $this->twoFriends();
        $id = $this->proposed($anna, $bob, 7);
        $this->post($bob, '/duels/accept', ['duelId' => $id]);
        $this->day('2026-11-18', [[$anna, 6, true], [$bob, 0, true]]);
        $this->day('2026-11-19', [[$anna, 6, true], [$bob, 12, true]]);
        // Today: Anna has checked in, Bob has logged 4 and not checked in yet.
        $this->day('2026-11-20', [[$anna, 3, true], [$bob, 4, false]]);

        $list = $this->call('GET', '/duels', headers: $this->authorization($anna));
        $detail = $this->post($anna, '/duels/show', ['duelId' => $id]);

        // Anna: 15 of 36. Bob: 12 of 24, his today does not count yet.
        $duel = [
            'id' => $id, 'status' => 'active', 'periodDays' => 7, 'challenger' => true, 'opponent' => self::publicUser($bob, 'Bob'),
            'createdAt' => '2026-11-17T12:00:00Z', 'expiresAt' => '2026-11-20T12:00:00Z', 'startsOn' => '2026-11-18', 'endsOn' => '2026-11-24',
            'mine' => ['reductionPercent' => 58.3, 'smokeFreeDays' => 0, 'checkIns' => 3, 'countedDays' => 3],
            'theirs' => ['reductionPercent' => 50, 'smokeFreeDays' => 1, 'checkIns' => 2, 'countedDays' => 2],
            'outcome' => null, 'xp' => null, 'provisional' => true,
        ];
        self::assertEquals(['duels' => [$duel]], $list->json());
        self::assertSame(200, $detail->status());
        self::assertEquals(
            [
                'duel' => $duel,
                'days' => [
                    ['day' => 1, 'mine' => 50, 'theirs' => 100], ['day' => 2, 'mine' => 50, 'theirs' => 0], ['day' => 3, 'mine' => 75, 'theirs' => null],
                    ['day' => 4, 'mine' => null, 'theirs' => null], ['day' => 5, 'mine' => null, 'theirs' => null],
                    ['day' => 6, 'mine' => null, 'theirs' => null], ['day' => 7, 'mine' => null, 'theirs' => null],
                ],
            ],
            $detail->json(),
        );
        // Nothing else: no count, no limit, no baseline, no time of a log, no address.
        foreach ([$list, $detail] as $response) {
            foreach (['count"', 'limit', 'baseline', 'b0', 'tenths', 'actual', 'expected', 'smokedAt', '10:00', 'email', 'example.org', 'timeZone'] as $private) {
                self::assertStringNotContainsStringIgnoringCase($private, $response->body());
            }
        }
    }

    public function test_FR_COMP_04_the_cron_job_decides_the_duel_once_the_last_day_of_both_is_final(): void
    {
        [$anna, $bob] = $this->twoFriends();
        $id = $this->proposed($anna, $bob, 7);
        $this->post($bob, '/duels/accept', ['duelId' => $id]);
        $this->day('2026-11-18', [[$anna, 6, true], [$bob, 0, true]]);
        $this->day('2026-11-19', [[$anna, 6, true], [$bob, 12, true]]);
        // Bob logs 4 on the 20th and never checks in, and does nothing at all on the 21st: both days count as his baseline.
        $this->day('2026-11-20', [[$anna, 3, true], [$bob, 4, false]]);
        $this->day('2026-11-21', [[$anna, 6, true]]);
        foreach (['2026-11-22', '2026-11-23', '2026-11-24'] as $date) {
            $this->day($date, [[$anna, 6, true], [$bob, 0, true]]);
        }

        // The 24th ended at 23:00 UTC and is final 48 hours later. A second earlier nothing is decided.
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-26 22:59:59 UTC'));
        self::assertStringContainsString('competitions:finalize: Expired 0 duel proposals, decided 0 duels.', $this->cronRun());
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-26 23:00:00 UTC'));
        self::assertStringContainsString('competitions:finalize: Expired 0 duel proposals, decided 1 duel.', $this->cronRun());

        // Anna: 39 of 84. Bob: 36 of 84 with four smoke-free days. Bob wins.
        $annas = [
            'id' => $id, 'status' => 'completed', 'periodDays' => 7, 'challenger' => true, 'opponent' => self::publicUser($bob, 'Bob'),
            'createdAt' => '2026-11-17T12:00:00Z', 'expiresAt' => '2026-11-20T12:00:00Z', 'startsOn' => '2026-11-18', 'endsOn' => '2026-11-24',
            'mine' => ['reductionPercent' => 53.6, 'smokeFreeDays' => 0, 'checkIns' => 7, 'countedDays' => 7],
            'theirs' => ['reductionPercent' => 57.1, 'smokeFreeDays' => 4, 'checkIns' => 5, 'countedDays' => 7],
            'outcome' => 'lost', 'xp' => 20, 'provisional' => false,
        ];
        self::assertEquals(['duels' => [$annas]], $this->call('GET', '/duels', headers: $this->authorization($anna))->json());
        $bobs = $this->post($bob, '/duels/show', ['duelId' => $id])->json();
        self::assertEquals(
            ['won', 100, $annas['theirs'], $annas['mine']],
            [self::at($bobs, 'duel', 'outcome'), self::at($bobs, 'duel', 'xp'), self::at($bobs, 'duel', 'mine'), self::at($bobs, 'duel', 'theirs')],
        );
        self::assertEquals(
            [[1, 100, 50], [2, 0, 50], [3, 0, 75], [4, 0, 50], [5, 100, 50], [6, 100, 50], [7, 100, 50]],
            array_map(static fn (mixed $day): array => [self::at((array) $day, 'day'), self::at((array) $day, 'mine'), self::at((array) $day, 'theirs')], (array) self::at($bobs, 'days')),
        );
        // Stored as it was scored, and the XP is in the ledger of each, on their own today (the 27th in Berlin).
        self::assertSame(
            [[
                'status' => 'completed', 'decided_at' => '2026-11-26 23:00:00', 'winner' => 'opponent',
                'challenger_b0_tenths' => 120, 'challenger_actual_tenths' => 390, 'challenger_expected_tenths' => 840, 'challenger_xp' => 20,
                'opponent_b0_tenths' => 120, 'opponent_actual_tenths' => 360, 'opponent_expected_tenths' => 840, 'opponent_xp' => 100, 'rules_version' => 'v1',
            ]],
            $this->sql()->rows(
                'SELECT status, decided_at, winner, challenger_b0_tenths, challenger_actual_tenths, challenger_expected_tenths, challenger_xp, '
                . 'opponent_b0_tenths, opponent_actual_tenths, opponent_expected_tenths, opponent_xp, rules_version FROM duels',
            ),
        );
        self::assertEquals(
            [
                ['user_id' => $anna->id->value, 'local_date' => '2026-11-27', 'amount' => 20, 'created_at' => '2026-11-26 23:00:00'],
                ['user_id' => $bob->id->value, 'local_date' => '2026-11-27', 'amount' => 100, 'created_at' => '2026-11-26 23:00:00'],
            ],
            $this->sql()->rows("SELECT user_id, local_date, amount, created_at FROM xp_events WHERE source = 'duel' ORDER BY amount"),
        );
        // What is stored as the total is the sum of the ledger, for both.
        self::assertSame(
            $this->sql()->rows('SELECT user_id, CAST(SUM(amount) AS UNSIGNED) AS xp FROM xp_events GROUP BY user_id ORDER BY user_id'),
            $this->sql()->rows('SELECT user_id, xp_total AS xp FROM user_progress ORDER BY user_id'),
        );
        // A second run decides nothing twice.
        self::assertStringContainsString('decided 0 duels.', $this->cronRun());
        self::assertSame(2, $this->sql()->value("SELECT COUNT(*) FROM xp_events WHERE source = 'duel'"));
    }

    public function test_FR_COMP_01_a_proposal_expires_after_72_hours_by_the_clock_and_the_cron_job_writes_it_down(): void
    {
        [$anna, $bob] = $this->twoFriends();
        $id = $this->proposed($anna, $bob, 7);

        $this->clock()->travelTo(new DateTimeImmutable('2026-11-20 12:00:00 UTC'));

        // Before the cron job was there: it is shown as expired and cannot be answered or taken back.
        self::assertSame('expired', self::at($this->call('GET', '/duels', headers: $this->authorization($bob))->json(), 'duels', '0', 'status'));
        self::assertProblem(409, 'duel_not_open', $this->post($bob, '/duels/accept', ['duelId' => $id]));
        self::assertProblem(409, 'duel_not_open', $this->post($bob, '/duels/decline', ['duelId' => $id]));
        self::assertProblem(409, 'duel_not_open', $this->post($anna, '/duels/cancel', ['duelId' => $id]));
        self::assertSame('proposed', $this->sql()->value('SELECT status FROM duels'));

        self::assertStringContainsString('competitions:finalize: Expired 1 duel proposal, decided 0 duels.', $this->cronRun());
        self::assertSame('expired', $this->sql()->value('SELECT status FROM duels'));
        // And a new one can be proposed.
        self::assertSame(201, $this->post($bob, '/duels', ['opponentId' => $anna->id->value, 'periodDays' => 7])->status());
    }

    public function test_FR_COMP_01_a_proposal_is_declined_by_the_one_who_was_challenged_or_taken_back_by_the_challenger(): void
    {
        [$anna, $bob] = $this->twoFriends();
        $carol = $this->onboarded('Carol');
        $this->befriend($anna, $carol);
        $toBob = $this->proposed($anna, $bob, 7);
        $toCarol = $this->proposed($anna, $carol, 90);

        // The wrong one of the two cannot do it, and nobody else can even see the duel.
        self::assertProblem(409, 'duel_not_open', $this->post($anna, '/duels/accept', ['duelId' => $toBob]));
        self::assertProblem(409, 'duel_not_open', $this->post($anna, '/duels/decline', ['duelId' => $toBob]));
        self::assertProblem(409, 'duel_not_open', $this->post($bob, '/duels/cancel', ['duelId' => $toBob]));
        foreach (['/duels/accept', '/duels/decline', '/duels/cancel', '/duels/show'] as $path) {
            self::assertProblem(404, 'duel_not_found', $this->post($carol, $path, ['duelId' => $toBob]));
            self::assertProblem(404, 'duel_not_found', $this->post($carol, $path, ['duelId' => TestData::uuid(999)]));
        }

        $declined = $this->post($bob, '/duels/decline', ['duelId' => $toBob]);
        $cancelled = $this->post($anna, '/duels/cancel', ['duelId' => $toCarol]);

        self::assertSame([204, ''], [$declined->status(), $declined->body()]);
        self::assertSame([204, ''], [$cancelled->status(), $cancelled->body()]);
        // The declined one stays in both lists. The one that was taken back is gone.
        self::assertSame(
            [['id' => $toBob, 'status' => 'declined', 'answered_at' => '2026-11-17 12:00:00']],
            $this->sql()->rows('SELECT id, status, answered_at FROM duels'),
        );
        self::assertSame('declined', self::at($this->call('GET', '/duels', headers: $this->authorization($anna))->json(), 'duels', '0', 'status'));
        self::assertSame(['duels' => []], $this->call('GET', '/duels', headers: $this->authorization($carol))->json());
        // Neither can be answered again.
        self::assertProblem(409, 'duel_not_open', $this->post($bob, '/duels/accept', ['duelId' => $toBob]));
        self::assertProblem(404, 'duel_not_found', $this->post($carol, '/duels/accept', ['duelId' => $toCarol]));
    }

    public function test_FR_COMP_05_what_cannot_be_proposed_or_accepted_is_refused_with_its_reason(): void
    {
        [$anna, $bob] = $this->twoFriends();
        $carol = $this->named('Carol');
        $dave = $this->onboarded('Dave');
        $this->befriend($anna, $carol);

        // Not a friend, and not somebody who exists.
        self::assertProblem(404, 'friend_not_found', $this->post($anna, '/duels', ['opponentId' => $dave->id->value, 'periodDays' => 7]));
        self::assertProblem(404, 'friend_not_found', $this->post($anna, '/duels', ['opponentId' => TestData::uuid(999), 'periodDays' => 7]));
        self::assertProblem(404, 'friend_not_found', $this->post($anna, '/duels', ['opponentId' => $anna->id->value, 'periodDays' => 7]));
        // Carol has not done the onboarding: she cannot challenge, and cannot accept.
        self::assertProblem(409, 'plan_required', $this->post($carol, '/duels', ['opponentId' => $anna->id->value, 'periodDays' => 7]));
        $toCarol = $this->proposed($anna, $carol, 7);
        self::assertProblem(409, 'plan_required', $this->post($carol, '/duels/accept', ['duelId' => $toCarol]));
        // One open duel per pair, whoever proposed it.
        $this->proposed($anna, $bob, 7);
        self::assertProblem(409, 'duel_exists', $this->post($anna, '/duels', ['opponentId' => $bob->id->value, 'periodDays' => 30]));
        self::assertProblem(409, 'duel_exists', $this->post($bob, '/duels', ['opponentId' => $anna->id->value, 'periodDays' => 30]));
        self::assertSame(2, $this->sql()->value('SELECT COUNT(*) FROM duels'));
    }

    public function test_FR_COMP_05_nobody_has_more_than_five_open_duels(): void
    {
        [$anna, $bob] = $this->twoFriends();
        $fromBob = $this->proposed($bob, $anna, 7);
        // Five friends more, and with each a proposal of her own: five open duels.
        $friends = [];
        foreach (['Carol', 'Dave', 'Erin', 'Frank', 'Grace', 'Heidi'] as $name) {
            $friends[$name] = $this->onboarded($name);
            $this->befriend($anna, $friends[$name]);
        }
        foreach (['Carol', 'Dave', 'Erin', 'Frank', 'Grace'] as $name) {
            $this->proposed($anna, $friends[$name], 7);
        }

        self::assertProblem(409, 'duel_limit_reached', $this->post($anna, '/duels', ['opponentId' => $friends['Heidi']->id->value, 'periodDays' => 7]));
        self::assertProblem(409, 'duel_limit_reached', $this->post($anna, '/duels/accept', ['duelId' => $fromBob]));
        // Heidi can challenge her all the same: a proposal from somebody else takes no place.
        self::assertSame(201, $this->post($friends['Heidi'], '/duels', ['opponentId' => $anna->id->value, 'periodDays' => 7])->status());
        self::assertSame(7, $this->sql()->value('SELECT COUNT(*) FROM duels'));
    }

    public function test_FR_FRND_02_when_two_stop_being_friends_their_duel_ends_without_a_winner_and_each_keeps_the_own_side(): void
    {
        [$anna, $bob] = $this->twoFriends();
        $carol = $this->onboarded('Carol');
        $this->befriend($anna, $carol);
        $running = $this->proposed($anna, $bob, 7);
        $this->post($bob, '/duels/accept', ['duelId' => $running]);
        $proposal = $this->proposed($carol, $anna, 7);
        $this->day('2026-11-18', [[$anna, 6, true], [$bob, 0, true]]);

        self::assertSame(204, $this->post($bob, '/friends/remove', ['userId' => $anna->id->value])->status());
        self::assertSame(204, $this->post($anna, '/friends/block', ['userId' => $carol->id->value])->status());

        self::assertSame([['id' => $running, 'status' => 'cancelled', 'winner' => null]], $this->sql()->rows('SELECT id, status, winner FROM duels'));
        self::assertNull($this->sql()->value('SELECT id FROM duels WHERE id = ?', [$proposal]));
        $annas = $this->call('GET', '/duels', headers: $this->authorization($anna))->json();
        self::assertSame(
            ['cancelled', null, null, null, null, null, false],
            array_map(static fn (string $member): mixed => self::at($annas, 'duels', '0', $member), ['status', 'opponent', 'mine', 'theirs', 'outcome', 'xp', 'provisional']),
        );
        // Long after its last day: nothing is decided, nobody earns anything.
        $this->clock()->travelTo(new DateTimeImmutable('2026-12-01 12:00:00 UTC'));
        self::assertStringContainsString('decided 0 duels.', $this->cronRun());
        self::assertSame(0, $this->sql()->value("SELECT COUNT(*) FROM xp_events WHERE source = 'duel'"));
    }

    public function test_FR_ACC_08_when_an_account_is_deleted_its_running_duels_are_forfeits_without_xp_and_its_proposals_are_gone(): void
    {
        [$anna, $bob] = $this->twoFriends();
        $carol = $this->onboarded('Carol');
        $this->befriend($anna, $carol);
        $running = $this->proposed($bob, $anna, 30);
        $this->post($anna, '/duels/accept', ['duelId' => $running]);
        $this->proposed($anna, $carol, 7);
        $this->day('2026-11-18', [[$anna, 0, true], [$bob, 6, true]]);

        self::assertSame(204, $this->call('DELETE', '/me', ['password' => TestAccounts::PASSWORD], $this->authorization($anna))->status());

        self::assertSame([], DatabaseContents::rowsMentioning($this->sql(), [$anna->id->value, 'anna@example.org']));
        self::assertSame(
            [['id' => $running, 'status' => 'forfeited', 'challenger_id' => $bob->id->value, 'opponent_id' => null, 'challenger_start' => '2026-11-18', 'opponent_start' => null, 'winner' => null]],
            $this->sql()->rows('SELECT id, status, challenger_id, opponent_id, challenger_start, opponent_start, winner FROM duels'),
        );
        // Bob keeps the duel in his list: the opponent left, there is no winner and no XP.
        $bobs = $this->call('GET', '/duels', headers: $this->authorization($bob))->json();
        self::assertSame(
            ['forfeited', null, '2026-11-18', null, null, null, null, false],
            array_map(static fn (string $member): mixed => self::at($bobs, 'duels', '0', $member), ['status', 'opponent', 'startsOn', 'mine', 'theirs', 'outcome', 'xp', 'provisional']),
        );
        self::assertSame(['duels' => []], $this->call('GET', '/duels', headers: $this->authorization($carol))->json());
        // And the cron job never decides it.
        $this->clock()->travelTo(new DateTimeImmutable('2027-01-15 12:00:00 UTC'));
        self::assertStringContainsString('decided 0 duels.', $this->cronRun());
        self::assertSame(0, $this->sql()->value("SELECT COUNT(*) FROM xp_events WHERE source = 'duel'"));
    }

    public function test_FR_ACC_07_the_export_holds_the_own_side_of_every_duel_and_of_the_opponent_the_id(): void
    {
        [$anna, $bob] = $this->twoFriends();
        $id = $this->proposed($anna, $bob, 7);
        $this->post($bob, '/duels/accept', ['duelId' => $id]);
        foreach (['2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22', '2026-11-23', '2026-11-24'] as $date) {
            $this->day($date, [[$anna, 6, true], [$bob, 3, true]]);
        }
        $this->clock()->travelTo(new DateTimeImmutable('2026-11-26 23:00:00 UTC'));
        $this->cronRun();

        $response = $this->call('GET', '/me/export', headers: $this->authorization($anna));

        self::assertEquals(
            [[
                'id' => $id, 'status' => 'completed', 'periodDays' => 7, 'role' => 'challenger', 'opponentId' => $bob->id->value,
                'createdAt' => '2026-11-17T12:00:00Z', 'expiresAt' => '2026-11-20T12:00:00Z', 'answeredAt' => '2026-11-17T12:00:00Z', 'startsOn' => '2026-11-18',
                'decidedAt' => '2026-11-26T23:00:00Z', 'outcome' => 'lost',
                'result' => ['baseline' => 12, 'actual' => 42, 'expected' => 84, 'smokeFreeDays' => 0, 'checkIns' => 7, 'xp' => 20],
                'rulesVersion' => 'v1',
            ]],
            $response->json()['duels'] ?? null,
        );
        // Exactly this and nothing of Bob's side. His name is not in the whole export.
        self::assertStringNotContainsString('Bob', $response->body());
    }

    public function test_a_malformed_request_is_refused_with_every_error(): void
    {
        [$anna, $bob] = $this->twoFriends();
        $proposal = ['opponentId' => $bob->id->value, 'periodDays' => 7];
        $cases = [
            'nothing' => [[], [['field' => 'opponentId', 'code' => 'required'], ['field' => 'periodDays', 'code' => 'required']]],
            'an opponent that is no UUID' => [['opponentId' => 'bob'] + $proposal, [['field' => 'opponentId', 'code' => 'invalid_format']]],
            'a period that is no number' => [['periodDays' => '7'] + $proposal, [['field' => 'periodDays', 'code' => 'wrong_type']]],
            'two weeks' => [['periodDays' => 14] + $proposal, [['field' => 'periodDays', 'code' => 'invalid_value']]],
            'no days at all' => [['periodDays' => 0] + $proposal, [['field' => 'periodDays', 'code' => 'invalid_value']]],
            'an unknown member' => [$proposal + ['stake' => 5], [['field' => 'stake', 'code' => 'unknown_field']]],
        ];
        foreach ($cases as $case => [$body, $errors]) {
            $problem = self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', '/duels', $body, $this->authorization($anna)));
            self::assertSame($errors, self::fieldCodes($problem), $case);
        }

        $references = [
            'no duel' => [[], [['field' => 'duelId', 'code' => 'required']]],
            'an id that is a number' => [['duelId' => 7], [['field' => 'duelId', 'code' => 'wrong_type']]],
            'an id that is no UUID' => [['duelId' => 'the one with Bob'], [['field' => 'duelId', 'code' => 'invalid_format']]],
            'an unknown member' => [['duelId' => TestData::uuid(1), 'why' => 'x'], [['field' => 'why', 'code' => 'unknown_field']]],
        ];
        foreach (['/duels/show', '/duels/accept', '/duels/decline', '/duels/cancel'] as $path) {
            foreach ($references as $case => [$body, $errors]) {
                $problem = self::assertProblem(422, 'validation_failed', $this->callInvalid('POST', $path, $body, $this->authorization($anna)));
                self::assertSame($errors, self::fieldCodes($problem), "{$path}: {$case}");
            }
        }
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM duels'));
    }

    public function test_FR_COMP_01_an_id_in_capitals_is_the_same_duel_and_the_same_friend(): void
    {
        [$anna, $bob] = $this->twoFriends();

        $proposed = $this->post($anna, '/duels', ['opponentId' => strtoupper($bob->id->value), 'periodDays' => 7]);
        self::assertSame(201, $proposed->status());
        $id = $proposed->json()['id'] ?? null;
        self::assertIsString($id);

        self::assertSame(200, $this->post($bob, '/duels/accept', ['duelId' => strtoupper($id)])->status());
        self::assertSame($id, self::at($this->post($bob, '/duels/show', ['duelId' => strtoupper($id)])->json(), 'duel', 'id'));
    }

    public function test_NFR_01_every_change_waits_for_the_account_of_the_other_user_too(): void
    {
        // A duel must not be accepted and taken back at the same moment, and a sixth duel must not
        // slip in beside the fifth. Here somebody else holds the account of the OTHER user, and the
        // application gives up after a second instead of waiting 50.
        [$anna, $bob] = $this->twoFriends();
        $carol = $this->onboarded('Carol');
        $this->befriend($anna, $carol);
        $fromBob = $this->proposed($bob, $anna, 7);
        $toCarol = $this->proposed($anna, $carol, 7);
        $dave = $this->onboarded('Dave');
        $this->befriend($anna, $dave);
        $this->service(Database::class)->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $changes = [
            [$dave, '/duels', ['opponentId' => $dave->id->value, 'periodDays' => 7]],
            [$bob, '/duels/accept', ['duelId' => $fromBob]],
            [$bob, '/duels/decline', ['duelId' => $fromBob]],
            [$carol, '/duels/cancel', ['duelId' => $toCarol]],
        ];
        $before = $this->sql()->rows('SELECT * FROM duels ORDER BY id');

        foreach ($changes as [$other, $path, $body]) {
            $held = TestDatabase::database()->pdo();
            $held->beginTransaction();
            try {
                $this->sql()->rows('SELECT id FROM users WHERE id = ? FOR UPDATE', [$other->id->value]);

                $response = $this->post($anna, $path, $body);
            } finally {
                $held->rollBack();
            }

            self::assertProblem(503, 'service_unavailable', $response);
        }
        self::assertSame($before, $this->sql()->rows('SELECT * FROM duels ORDER BY id'));
    }

    public function test_the_contract_states_the_same_values_and_limits_as_the_code(): void
    {
        $document = Yaml::parseFile(Paths::openApi());
        self::assertIsArray($document);
        $schema = static fn (string ...$keys): mixed => self::at($document, 'components', 'schemas', ...$keys);

        self::assertSame(Duel::PERIODS, $schema('DuelPeriod', 'enum'));
        self::assertSame(array_map(static fn (DuelStatus $status): string => $status->value, DuelStatus::cases()), $schema('DuelStatus', 'enum'));
        self::assertSame('#/components/schemas/DuelStatus', $schema('ExportedDuel', 'properties', 'status', '$ref'));
        self::assertSame(ShowDuels::MAX_LISTED, $schema('DuelList', 'properties', 'duels', 'maxItems'));
        self::assertSame(max(Duel::PERIODS), $schema('DuelDetail', 'properties', 'days', 'maxItems'));
        self::assertSame(max(Duel::PERIODS), $schema('DuelDetail', 'properties', 'days', 'items', 'properties', 'day', 'maximum'));
        self::assertSame([72, 5], [Duel::OPEN_FOR_HOURS, DuelInvitations::MAX_OPEN]);
        $notes = self::at($document, 'paths', '/duels', 'post', 'description');
        self::assertIsString($notes);
        self::assertStringContainsString('72 hours', $notes);
        self::assertStringContainsString('at most 5 open duels', (string) preg_replace('/\s+/', ' ', $notes));
    }

    /**
     * Anna and Bob: friends, both with a plan since today.
     *
     * @return array{User, User}
     */
    private function twoFriends(): array
    {
        $anna = $this->onboarded('Anna');
        $bob = $this->onboarded('Bob');
        $this->befriend($anna, $bob);

        return [$anna, $bob];
    }

    /**
     * A confirmed account whose display name is $name and whose address is <name>@example.org.
     */
    private function named(string $name): User
    {
        $user = $this->givenUser(strtolower($name) . '@example.org');
        self::assertSame(200, $this->call('PATCH', '/me', ['displayName' => $name], $this->authorization($user))->status());

        return $user;
    }

    private function onboarded(string $name): User
    {
        $user = $this->named($name);
        self::assertSame(200, $this->call('PUT', '/me/plan', self::PLAN, $this->authorization($user))->status());

        return $user;
    }

    private function befriend(User $from, User $to): void
    {
        $code = $this->call('GET', '/me/invite', headers: $this->authorization($to))->json()['code'] ?? null;
        self::assertSame(200, $this->post($from, '/friends/requests', ['code' => $code])->status());
        self::assertSame(200, $this->post($to, '/friends/requests/accept', ['userId' => $from->id->value])->status());
    }

    /**
     * $challenger proposes a duel to $opponent.
     *
     * @return string The id of the duel.
     */
    private function proposed(User $challenger, User $opponent, int $periodDays): string
    {
        $response = $this->post($challenger, '/duels', ['opponentId' => $opponent->id->value, 'periodDays' => $periodDays]);
        self::assertSame(201, $response->status(), $response->body());
        $id = $response->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * Lets a day happen: moves the clock to noon UTC of the date, and for each user logs the count
     * (at ten in the morning) and, if the last value says so, checks the day in.
     *
     * @param list<array{User, int, bool}> $users
     */
    private function day(string $date, array $users): void
    {
        $this->clock()->travelTo(new DateTimeImmutable("{$date} 12:00:00 UTC"));
        foreach ($users as [$user, $count, $checkIn]) {
            if ($count > 0) {
                $log = ['id' => TestData::uuid(++$this->logs), 'smokedAt' => "{$date}T10:00:00Z", 'productType' => 'rolled', 'count' => $count];
                self::assertSame(200, $this->post($user, '/logs/sync', ['items' => [$log]])->status());
            }
            if ($checkIn) {
                self::assertSame(200, $this->call('POST', "/days/{$date}/check-in", headers: $this->authorization($user))->status());
            }
        }
    }

    /**
     * Runs `cron:run` with the application under test.
     *
     * @return string The one line it prints.
     */
    private function cronRun(): string
    {
        $output = new BufferedOutput();
        $this->service(Console::class)->run(['cron:run'], $output);

        return implode("\n", $output->lines);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(User $user, string $path, array $body): ApiResponse
    {
        return $this->call('POST', $path, $body, $this->authorization($user));
    }

    /**
     * The value at a path of keys, or null.
     *
     * @param array<array-key, mixed> $node
     */
    private static function at(array $node, string ...$keys): mixed
    {
        $value = $node;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array{userId: string, displayName: string, avatar: string}
     */
    private static function publicUser(User $user, string $name): array
    {
        return ['userId' => $user->id->value, 'displayName' => $name, 'avatar' => 'avatar-01'];
    }
}
