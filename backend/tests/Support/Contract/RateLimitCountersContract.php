<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Contract;

use DateTimeImmutable;
use DateTimeZone;
use SmokeDetector\Application\RateLimit\RateLimitCounters;
use SmokeDetector\Tests\Support\TestData;

/**
 * What every RateLimitCounters does. The in-memory one and the PDO one both run these tests.
 */
trait RateLimitCountersContract
{
    abstract protected function counters(): RateLimitCounters;

    public function test_NFR_05_hits_are_counted_per_key_and_window(): void
    {
        $first = TestData::time('2026-11-17 12:00:00');
        $second = TestData::time('2026-11-17 12:15:00');

        self::assertSame(1, $this->counters()->increment('login.account:a', $first));
        self::assertSame(2, $this->counters()->increment('login.account:a', $first));
        self::assertSame(3, $this->counters()->increment('login.account:a', $first));
        self::assertSame(1, $this->counters()->increment('login.account:a', $second));
        self::assertSame(1, $this->counters()->increment('login.account:b', $first));
        // Keys are compared exactly, case included.
        self::assertSame(1, $this->counters()->increment('login.account:A', $first));
        self::assertSame(4, $this->counters()->increment('login.account:a', $first));
    }

    public function test_NFR_05_the_window_is_a_moment_and_not_a_wall_clock_reading(): void
    {
        $this->counters()->increment('k', new DateTimeImmutable('2026-11-17 13:00:00', new DateTimeZone('Europe/Berlin')));

        self::assertSame(2, $this->counters()->increment('k', TestData::time('2026-11-17 12:00:00')));
    }

    public function test_NFR_05_a_hit_can_be_taken_back_but_never_below_zero(): void
    {
        $window = TestData::time('2026-11-17 12:00:00');
        $other = TestData::time('2026-11-17 12:15:00');
        $this->counters()->increment('k', $window);
        $this->counters()->increment('k', $window);
        $this->counters()->increment('k', $other);
        $this->counters()->increment('other', $window);

        $this->counters()->decrement('k', $window);
        self::assertSame(2, $this->counters()->increment('k', $window));

        $this->counters()->decrement('k', $window);
        $this->counters()->decrement('k', $window);
        $this->counters()->decrement('k', $window);
        $this->counters()->decrement('k', $window);
        // No credit was built up, and no other counter was touched.
        self::assertSame(1, $this->counters()->increment('k', $window));
        self::assertSame(2, $this->counters()->increment('k', $other));
        self::assertSame(2, $this->counters()->increment('other', $window));

        // Taking back from a counter that does not exist does nothing.
        $this->counters()->decrement('never-counted', $window);
        self::assertSame(1, $this->counters()->increment('never-counted', $window));
    }

    public function test_NFR_05_counters_of_windows_that_started_before_a_moment_are_deleted(): void
    {
        $this->counters()->increment('old', TestData::time('2026-11-15 11:59:59'));
        $this->counters()->increment('old', TestData::time('2026-11-15 11:00:00'));
        $this->counters()->increment('edge', TestData::time('2026-11-15 12:00:00'));
        $this->counters()->increment('new', TestData::time('2026-11-17 12:00:00'));

        $deleted = $this->counters()->deleteWindowsStartedBefore(TestData::time('2026-11-15 12:00:00'));

        self::assertSame(2, $deleted);
        self::assertSame(1, $this->counters()->increment('old', TestData::time('2026-11-15 11:59:59')));
        self::assertSame(2, $this->counters()->increment('edge', TestData::time('2026-11-15 12:00:00')));
        self::assertSame(2, $this->counters()->increment('new', TestData::time('2026-11-17 12:00:00')));
    }

    public function test_NFR_05_deleting_a_key_removes_exactly_that_key_in_all_windows(): void
    {
        $first = TestData::time('2026-11-17 12:00:00');
        $second = TestData::time('2026-11-17 13:00:00');
        $this->counters()->increment('mail.email:abc', $first);
        $this->counters()->increment('mail.email:abc', $second);
        // Not the same key: a longer rule name with the same ending, other case, a wildcard's luck.
        $this->counters()->increment('login.mail.email:abc', $first);
        $this->counters()->increment('mail.email:ABC', $first);
        $this->counters()->increment('mail.email:a_c', $first);

        self::assertSame(0, $this->counters()->deleteKey('mail.email:a%c'));
        self::assertSame(0, $this->counters()->deleteKey('email:abc'));
        self::assertSame(2, $this->counters()->deleteKey('mail.email:abc'));

        self::assertSame(1, $this->counters()->increment('mail.email:abc', $first));
        self::assertSame(1, $this->counters()->increment('mail.email:abc', $second));
        foreach (['login.mail.email:abc', 'mail.email:ABC', 'mail.email:a_c'] as $kept) {
            self::assertSame(2, $this->counters()->increment($kept, $first), $kept);
        }
    }

    public function test_FR_ACC_08_every_counter_whose_key_ends_with_a_suffix_is_deleted_in_all_windows(): void
    {
        $first = TestData::time('2026-11-17 12:00:00');
        $second = TestData::time('2026-11-17 13:00:00');
        $this->counters()->increment('login.account:abc', $first);
        $this->counters()->increment('login.account:abc', $second);
        $this->counters()->increment('mail.email:abc', $first);
        $this->counters()->increment('mail.email:abc', $first);
        // Not the same ending: longer, other case, the suffix somewhere else, or with a wildcard's luck.
        $this->counters()->increment('login.account:xabc', $first);
        $this->counters()->increment('login.account:ABC', $first);
        $this->counters()->increment('login.account:abc.', $first);
        $this->counters()->increment('login.account:a_c', $first);
        $this->counters()->increment(':abc:login', $first);

        self::assertSame(3, $this->counters()->deleteKeysEndingWith(':abc'));

        self::assertSame(1, $this->counters()->increment('login.account:abc', $first));
        self::assertSame(1, $this->counters()->increment('login.account:abc', $second));
        self::assertSame(1, $this->counters()->increment('mail.email:abc', $first));
        foreach (['login.account:xabc', 'login.account:ABC', 'login.account:abc.', 'login.account:a_c', ':abc:login'] as $kept) {
            self::assertSame(2, $this->counters()->increment($kept, $first), $kept);
        }
    }

    public function test_FR_ACC_08_a_suffix_is_text_and_never_a_pattern(): void
    {
        $window = TestData::time('2026-11-17 12:00:00');
        $this->counters()->increment('login.account:abc', $window);
        $this->counters()->increment('login.account:a%c', $window);

        self::assertSame(0, $this->counters()->deleteKeysEndingWith('%'));
        self::assertSame(0, $this->counters()->deleteKeysEndingWith(':a_c'));
        self::assertSame(0, $this->counters()->deleteKeysEndingWith(':%'));
        self::assertSame(1, $this->counters()->deleteKeysEndingWith(':a%c'));
        self::assertSame(2, $this->counters()->increment('login.account:abc', $window));
    }
}
