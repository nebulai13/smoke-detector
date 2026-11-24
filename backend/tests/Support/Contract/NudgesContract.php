<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Contract;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Nudge\Nudge;
use SmokeDetector\Application\Nudge\Nudges;
use SmokeDetector\Tests\Support\TestData;

/**
 * What every implementation of the nudges does (HLD §4.14): the PDO one against the database and the
 * in-memory one the use case tests run on.
 */
trait NudgesContract
{
    abstract protected function nudges(): Nudges;

    /** Makes sure the accounts exist, where rows cannot exist without one. */
    abstract protected function givenUsers(UserId ...$ids): void;

    public function test_FR_SOC_03_a_nudge_is_found_by_sender_receiver_and_day_with_every_value(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3));
        $delivered = self::nudge(1, 2, '2026-11-17', '2026-11-17 12:00:05', true);
        $kept = self::nudge(1, 3, '2026-11-17', '2026-11-17 12:00:06', false);
        $next = self::nudge(1, 2, '2026-11-18', '2026-11-17 23:10:00', true);
        $back = self::nudge(2, 1, '2026-11-17', '2026-11-17 13:00:00', true);

        foreach ([$delivered, $kept, $next, $back] as $nudge) {
            self::assertTrue($this->nudges()->add($nudge));
        }

        self::assertEquals($delivered, $this->nudges()->find(TestData::userId(1), TestData::userId(2), TestData::date('2026-11-17')));
        self::assertEquals($kept, $this->nudges()->find(TestData::userId(1), TestData::userId(3), TestData::date('2026-11-17')));
        self::assertEquals($next, $this->nudges()->find(TestData::userId(1), TestData::userId(2), TestData::date('2026-11-18')));
        self::assertEquals($back, $this->nudges()->find(TestData::userId(2), TestData::userId(1), TestData::date('2026-11-17')));
        self::assertNull($this->nudges()->find(TestData::userId(3), TestData::userId(1), TestData::date('2026-11-17')));
        self::assertNull($this->nudges()->find(TestData::userId(1), TestData::userId(2), TestData::date('2026-11-19')));
    }

    public function test_FR_SOC_03_there_is_one_nudge_per_sender_receiver_and_day_and_the_first_stays(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $first = self::nudge(1, 2, '2026-11-17', '2026-11-17 12:00:05', false);
        self::assertTrue($this->nudges()->add($first));

        // Again on the same day, when it would be delivered: nothing is stored, and the answer says so.
        self::assertFalse($this->nudges()->add(self::nudge(1, 2, '2026-11-17', '2026-11-17 18:00:00', true)));

        self::assertEquals([$first], $this->nudges()->sentBy(TestData::userId(1)));
    }

    public function test_FR_SOC_03_the_delivered_nudges_to_a_user_since_a_moment_come_oldest_first(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3), TestData::userId(4));
        $late = self::nudge(3, 1, '2026-11-17', '2026-11-17 15:00:00', true);
        $early = self::nudge(2, 1, '2026-11-17', '2026-11-17 09:00:00', true);
        $atTheMoment = self::nudge(4, 1, '2026-11-16', '2026-11-16 23:00:00', true);
        // Before the moment, not delivered, and to somebody else.
        $before = self::nudge(2, 1, '2026-11-16', '2026-11-16 22:59:59', true);
        $kept = self::nudge(4, 1, '2026-11-17', '2026-11-17 10:00:00', false);
        $other = self::nudge(1, 2, '2026-11-17', '2026-11-17 11:00:00', true);
        foreach ([$late, $early, $atTheMoment, $before, $kept, $other] as $nudge) {
            $this->nudges()->add($nudge);
        }

        self::assertEquals([$atTheMoment, $early, $late], $this->nudges()->deliveredTo(TestData::userId(1), TestData::time('2026-11-16 23:00:00')));
        self::assertEquals([$late], $this->nudges()->deliveredTo(TestData::userId(1), TestData::time('2026-11-17 09:00:01')));
        self::assertSame([], $this->nudges()->deliveredTo(TestData::userId(3), TestData::time('2026-11-01 00:00:00')));
    }

    public function test_FR_ACC_07_what_a_user_sent_and_what_a_user_received_come_oldest_first(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3));
        $second = self::nudge(1, 3, '2026-11-17', '2026-11-17 12:00:06', false);
        $first = self::nudge(1, 2, '2026-11-17', '2026-11-17 12:00:05', true);
        $received = self::nudge(2, 1, '2026-11-17', '2026-11-17 13:00:00', false);
        foreach ([$second, $first, $received] as $nudge) {
            $this->nudges()->add($nudge);
        }

        self::assertEquals([$first, $second], $this->nudges()->sentBy(TestData::userId(1)));
        // Also the ones that were not delivered: they are stored about the user all the same.
        self::assertEquals([$received], $this->nudges()->receivedBy(TestData::userId(1)));
        self::assertEquals([$first], $this->nudges()->receivedBy(TestData::userId(2)));
        self::assertSame([], $this->nudges()->sentBy(TestData::userId(3)));
    }

    private static function nudge(int $from, int $to, string $day, string $createdAt, bool $delivered): Nudge
    {
        return new Nudge(TestData::userId($from), TestData::userId($to), TestData::date($day), TestData::time($createdAt), $delivered);
    }
}
