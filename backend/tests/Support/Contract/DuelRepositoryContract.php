<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Contract;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Competition\Duel;
use SmokeDetector\Application\Competition\Duels;
use SmokeDetector\Application\Competition\DuelStatus;
use SmokeDetector\Application\Competition\DuelWinner;
use SmokeDetector\Application\Competition\SideResult;
use SmokeDetector\Application\Day\ProgressRecords;
use SmokeDetector\Tests\Support\TestData;

/**
 * What every implementation of the duel repository does (HLD §4.8): the PDO one against the
 * database and the in-memory one the use case tests run on.
 */
trait DuelRepositoryContract
{
    abstract protected function duels(): Duels;

    abstract protected function progressRecords(): ProgressRecords;

    /** Makes sure the accounts exist, where rows cannot exist without one. */
    abstract protected function givenUsers(UserId ...$ids): void;

    public function test_FR_COMP_01_a_saved_duel_is_found_by_its_id_with_every_value(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $proposal = TestData::duel(10, 1, 2, 30, createdAt: '2026-11-17 12:00:01');
        $running = TestData::duel(11, 2, 1, 180, DuelStatus::Active, '2026-11-16 08:00:00', '2026-11-17 09:30:00', '2026-11-18', '2026-11-19');
        $decided = TestData::duel(
            12, 1, 2, 7, DuelStatus::Completed, '2026-11-01 08:00:00', '2026-11-02 09:00:00', '2026-11-03', '2026-11-03',
            TestData::decision(DuelWinner::Draw, new SideResult(10, 0, 70, 7, 7, 50), new SideResult(805, 65535, 5635, 0, 0, 50), '2026-11-12 23:00:40', 'v1'),
        );

        foreach ([$proposal, $running, $decided] as $duel) {
            $this->duels()->save($duel);
        }

        self::assertEquals($proposal, $this->duels()->find(TestData::uuid(10)));
        self::assertEquals($running, $this->duels()->find(TestData::uuid(11)));
        self::assertEquals($decided, $this->duels()->find(TestData::uuid(12)));
        self::assertNull($this->duels()->find(TestData::uuid(13)));
    }

    public function test_FR_COMP_02_saving_a_known_id_again_replaces_the_duel(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->duels()->save(TestData::duel(10));

        $accepted = TestData::duel(10, 1, 2, 7, DuelStatus::Active, answeredAt: '2026-11-17 13:00:00', challengerStart: '2026-11-18', opponentStart: '2026-11-18');
        $this->duels()->save($accepted);

        self::assertEquals($accepted, $this->duels()->find(TestData::uuid(10)));
        self::assertEquals([$accepted], $this->duels()->ofUser(TestData::userId(1), 100));
    }

    public function test_FR_ACC_08_a_duel_keeps_the_side_that_is_left_when_an_account_is_gone(): void
    {
        $this->givenUsers(TestData::userId(1));
        // The challenger's account was deleted: no id, no start, no result of that side.
        $forfeited = TestData::duel(10, 0, 1, 30, DuelStatus::Forfeited, '2026-11-01 08:00:00', '2026-11-02 09:00:00', null, '2026-11-03');
        $completed = TestData::duel(
            11, 1, 0, 7, DuelStatus::Completed, '2026-10-01 08:00:00', '2026-10-02 09:00:00', '2026-10-03', null,
            TestData::decision(DuelWinner::Opponent, new SideResult(120, 840, 840, 0, 7, 20), null),
        );

        $this->duels()->save($forfeited);
        $this->duels()->save($completed);

        self::assertEquals($forfeited, $this->duels()->find(TestData::uuid(10)));
        self::assertEquals($completed, $this->duels()->find(TestData::uuid(11)));
        self::assertEquals([$forfeited, $completed], $this->duels()->ofUser(TestData::userId(1), 100));
    }

    public function test_FR_COMP_01_a_deleted_duel_is_gone_and_deleting_nothing_does_nothing(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->duels()->save(TestData::duel(10));
        $this->duels()->save(TestData::duel(11, 2, 1));

        $this->duels()->delete(TestData::uuid(10));
        $this->duels()->delete(TestData::uuid(10));

        self::assertNull($this->duels()->find(TestData::uuid(10)));
        self::assertEquals([TestData::duel(11, 2, 1)], $this->duels()->ofUser(TestData::userId(1), 100));
    }

    public function test_FR_COMP_01_the_duels_of_a_user_are_listed_newest_first_from_both_sides_up_to_the_limit(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3));
        $this->duels()->save(TestData::duel(10, 1, 2, createdAt: '2026-11-10 08:00:00'));
        $this->duels()->save(TestData::duel(11, 3, 1, status: DuelStatus::Declined, createdAt: '2026-11-12 08:00:00'));
        // Made in the same second as duel 11: the larger id counts as newer.
        $this->duels()->save(TestData::duel(12, 1, 3, status: DuelStatus::Expired, createdAt: '2026-11-12 08:00:00'));
        $this->duels()->save(TestData::duel(13, 2, 3, createdAt: '2026-11-15 08:00:00'));


        self::assertSame([TestData::uuid(12), TestData::uuid(11), TestData::uuid(10)], self::ids($this->duels()->ofUser(TestData::userId(1), 100)));
        self::assertSame([TestData::uuid(12), TestData::uuid(11)], self::ids($this->duels()->ofUser(TestData::userId(1), 2)));
        self::assertSame([TestData::uuid(13), TestData::uuid(10)], self::ids($this->duels()->ofUser(TestData::userId(2), 100)));
        self::assertSame([], $this->duels()->ofUser(TestData::userId(4), 100));
    }

    public function test_FR_COMP_05_the_open_duels_of_a_user_are_the_proposals_and_the_running_ones_oldest_first(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3));
        $this->duels()->save(TestData::duel(10, 1, 2, status: DuelStatus::Active, createdAt: '2026-11-12 08:00:00', answeredAt: '2026-11-12 09:00:00', challengerStart: '2026-11-13', opponentStart: '2026-11-13'));
        $this->duels()->save(TestData::duel(11, 3, 1, createdAt: '2026-11-10 08:00:00'));
        // A proposal whose 72 hours are over is still stored as a proposal until the cron job gets to it.
        $this->duels()->save(TestData::duel(12, 1, 3, createdAt: '2026-11-10 08:00:00', expiresAt: '2026-11-10 09:00:00'));
        foreach ([[13, DuelStatus::Declined], [14, DuelStatus::Expired], [15, DuelStatus::Cancelled], [16, DuelStatus::Completed], [17, DuelStatus::Forfeited]] as [$number, $status]) {
            $this->duels()->save(TestData::duel($number, 1, 2, status: $status, createdAt: '2026-11-01 08:00:00'));
        }
        $this->duels()->save(TestData::duel(18, 2, 3, createdAt: '2026-11-01 08:00:00'));


        self::assertSame([TestData::uuid(11), TestData::uuid(12), TestData::uuid(10)], self::ids($this->duels()->openOf(TestData::userId(1))));
        self::assertSame([TestData::uuid(18), TestData::uuid(10)], self::ids($this->duels()->openOf(TestData::userId(2))));
        self::assertSame([], $this->duels()->openOf(TestData::userId(4)));
    }

    public function test_FR_COMP_01_proposals_whose_time_is_over_are_found_longest_over_first(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3));
        $this->duels()->save(TestData::duel(10, 1, 2, createdAt: '2026-11-14 12:00:01'));
        $this->duels()->save(TestData::duel(11, 1, 3, createdAt: '2026-11-14 12:00:00'));
        $this->duels()->save(TestData::duel(12, 2, 3, createdAt: '2026-11-13 12:00:00'));
        // Over in the same second as duel 12.
        $this->duels()->save(TestData::duel(13, 3, 2, createdAt: '2026-11-13 12:00:00'));
        // Over long ago, but not a proposal any more.
        $this->duels()->save(TestData::duel(14, 3, 1, status: DuelStatus::Declined, createdAt: '2026-11-01 12:00:00'));
        $this->duels()->save(TestData::duel(15, 2, 1, status: DuelStatus::Active, createdAt: '2026-11-01 12:00:00', answeredAt: '2026-11-01 13:00:00', challengerStart: '2026-11-02', opponentStart: '2026-11-02'));

        // 72 hours after 12:00:00 on the 14th, to the second.
        $now = TestData::time('2026-11-17 12:00:00');

        self::assertSame([TestData::uuid(12), TestData::uuid(13), TestData::uuid(11)], self::ids($this->duels()->expired($now, 50)));
        self::assertSame([TestData::uuid(12), TestData::uuid(13)], self::ids($this->duels()->expired($now, 2)));
        self::assertSame([], $this->duels()->expired(TestData::time('2026-11-16 11:59:59'), 50));
    }

    public function test_FR_COMP_04_a_duel_can_be_decided_when_the_last_day_of_both_users_is_final(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3), TestData::userId(4));
        // Every day before the next day to finalize is final.
        $this->progressRecords()->save(TestData::progress(1, null, 0, 0, '2026-11-18', '2026-11-20 23:00:00', '2026-11-22 23:00:00'));
        $this->progressRecords()->save(TestData::progress(2, null, 0, 0, '2026-11-17', '2026-11-19 23:00:00', '2026-11-22 23:00:00'));
        $this->progressRecords()->save(TestData::progress(3, null, 0, 0, '2026-11-25', '2026-11-27 23:00:00', '2026-11-29 23:00:00'));
        $running = static fn (int $number, int $challenger, int $opponent, string $challengerStart, string $opponentStart, string $answeredAt = '2026-11-09 10:00:00'): Duel => TestData::duel(
            $number, $challenger, $opponent, 7, DuelStatus::Active, '2026-11-09 08:00:00', $answeredAt, $challengerStart, $opponentStart,
        );
        // Both through: the last days are the 17th and the 16th.
        $this->duels()->save($running(10, 1, 2, '2026-11-11', '2026-11-10', '2026-11-09 11:00:00'));
        // The opponent's last day is the 17th, which is the opponent's next day to finalize: not yet.
        $this->duels()->save($running(11, 1, 2, '2026-11-10', '2026-11-11'));
        // The challenger's last day is the 18th: not yet.
        $this->duels()->save($running(12, 1, 3, '2026-11-12', '2026-11-10'));
        // Through, and accepted before duel 10.
        $this->duels()->save($running(13, 3, 1, '2026-11-18', '2026-11-11', '2026-11-09 10:00:00'));
        // Accepted in the same second as duel 13.
        $this->duels()->save($running(14, 3, 2, '2026-11-10', '2026-11-10', '2026-11-09 10:00:00'));
        // A user without progress is never through, and a duel that is not running is not decided.
        $this->duels()->save($running(15, 4, 1, '2026-11-01', '2026-11-01'));
        $this->duels()->save(TestData::duel(16, 1, 2, 7, DuelStatus::Completed, '2026-11-01 08:00:00', '2026-11-01 09:00:00', '2026-11-02', '2026-11-02', TestData::decision()));
        $this->duels()->save(TestData::duel(17, 1, 2, 7, DuelStatus::Cancelled, '2026-11-01 08:00:00', '2026-11-01 09:00:00', '2026-11-02', '2026-11-02'));


        self::assertSame([TestData::uuid(13), TestData::uuid(14), TestData::uuid(10)], self::ids($this->duels()->decidable(50)));
        self::assertSame([TestData::uuid(13)], self::ids($this->duels()->decidable(1)));
    }

    /**
     * @param list<Duel> $duels
     *
     * @return list<string>
     */
    private static function ids(array $duels): array
    {
        return array_map(static fn (Duel $duel): string => $duel->id, $duels);
    }
}
