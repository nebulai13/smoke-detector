<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\RateLimit;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\RateLimit\RateLimit;
use SmokeDetector\Application\RateLimit\RateLimiter;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;
use SmokeDetector\Application\RateLimit\RateLimitKeyHasher;
use SmokeDetector\Tests\Support\InMemory\InMemoryRateLimitCounters;
use SmokeDetector\Tests\Support\InMemory\RecordingRateLimitCounters;
use SmokeDetector\Tests\Support\RateLimitKey;

/**
 * Fixed-window rate limiting (HLD §4.5 "Rate limits", NFR-05).
 */
final class RateLimiterTest extends TestCase
{
    private InMemoryRateLimitCounters $counters;
    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $this->counters = new InMemoryRateLimitCounters();
        $this->limiter = new RateLimiter($this->counters, RateLimitKey::hasher());
    }

    public function test_NFR_05_hits_up_to_the_limit_pass_and_the_next_one_is_refused(): void
    {
        $limit = new RateLimit('login.account', 5, 900);
        $now = self::at('2026-11-17 12:07:30');

        for ($i = 1; $i <= 5; $i++) {
            $this->limiter->consume($limit, 'anna@example.org', $now);
        }

        $this->expectException(RateLimitExceeded::class);
        $this->limiter->consume($limit, 'anna@example.org', $now);
    }

    public function test_NFR_05_a_hit_can_be_tried_without_an_exception_and_is_counted_the_same_way(): void
    {
        // For work that is skipped silently over the limit, where a 429 would be an answer of its own.
        $limit = new RateLimit('mail.email', 3, 3600);
        $now = self::at('2026-11-17 12:07:30');

        $outcomes = [];
        for ($i = 1; $i <= 5; $i++) {
            $outcomes[] = $this->limiter->tryConsume($limit, 'anna@example.org', $now);
        }

        self::assertSame([true, true, true, false, false], $outcomes);
        // One counter for both ways of counting, and a refused try stays counted like a refused hit.
        self::assertSame(5, $this->counters->hits(RateLimitKey::of('mail.email', 'anna@example.org'), self::at('2026-11-17 12:00:00')));
        $this->expectException(RateLimitExceeded::class);
        $this->limiter->consume($limit, 'anna@example.org', $now);
    }

    public function test_NFR_05_resetting_a_rule_for_a_subject_removes_that_counter_and_no_other(): void
    {
        $account = new RateLimit('login.account', 5, 900);
        $client = new RateLimit('login.client', 5, 900);
        $earlier = self::at('2026-11-17 11:50:00');
        $now = self::at('2026-11-17 12:07:30');
        $this->limiter->consume($account, 'anna@example.org', $earlier);
        $this->limiter->consume($account, 'anna@example.org', $now);
        $this->limiter->consume($account, 'bob@example.org', $now);
        $this->limiter->consume($client, 'anna@example.org', $now);

        $this->limiter->reset($account, 'anna@example.org');

        self::assertSame(
            [RateLimitKey::of('login.account', 'bob@example.org'), RateLimitKey::of('login.client', 'anna@example.org')],
            array_keys($this->counters->all()),
        );
    }

    public function test_NFR_05_resetting_a_rule_leaves_a_rule_whose_name_ends_the_same_way_alone(): void
    {
        // "login.mail.email" ends with "mail.email": the reset of one must not reach the other.
        $mails = new RateLimit('mail.email', 3, 3600);
        $loginMails = new RateLimit('login.mail.email', 1, 3600);
        $now = self::at('2026-11-17 12:07:30');
        $this->limiter->consume($mails, 'anna@example.org', $now);
        $this->limiter->consume($loginMails, 'anna@example.org', $now);

        $this->limiter->reset($mails, 'anna@example.org');

        self::assertSame([RateLimitKey::of('login.mail.email', 'anna@example.org')], array_keys($this->counters->all()));
    }

    public function test_NFR_05_a_limit_of_one_allows_exactly_one_hit(): void
    {
        $limit = new RateLimit('one', 1, 60);
        $this->limiter->consume($limit, 'x', self::at('2026-11-17 12:00:00'));

        $this->expectException(RateLimitExceeded::class);
        $this->limiter->consume($limit, 'x', self::at('2026-11-17 12:00:00'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function momentsInTheWindow(): iterable
    {
        yield 'first second of the window' => ['2026-11-17 12:00:00', 900];
        yield 'in the middle' => ['2026-11-17 12:07:30', 450];
        yield 'last second of the window' => ['2026-11-17 12:14:59', 1];
        yield 'first second of the next window' => ['2026-11-17 12:15:00', 900];
    }

    #[DataProvider('momentsInTheWindow')]
    public function test_NFR_05_retry_after_is_the_time_until_the_window_ends(string $now, int $retryAfter): void
    {
        $limit = new RateLimit('login.account', 1, 900);
        $this->limiter->consume($limit, 'anna@example.org', self::at($now));

        try {
            $this->limiter->consume($limit, 'anna@example.org', self::at($now));
            self::fail('Expected the hit to be refused.');
        } catch (RateLimitExceeded $exceeded) {
            self::assertSame($retryAfter, $exceeded->retryAfterSeconds);
            self::assertSame('Too many requests.', $exceeded->getMessage());
        }
    }

    public function test_NFR_05_windows_are_fixed_and_a_new_window_starts_from_zero(): void
    {
        $limit = new RateLimit('login.account', 2, 900);
        $this->limiter->consume($limit, 'anna@example.org', self::at('2026-11-17 12:00:00'));
        $this->limiter->consume($limit, 'anna@example.org', self::at('2026-11-17 12:14:59'));

        // One second later is the next window, however close the hits are to each other.
        $this->limiter->consume($limit, 'anna@example.org', self::at('2026-11-17 12:15:00'));
        $this->limiter->consume($limit, 'anna@example.org', self::at('2026-11-17 12:29:59'));

        $key = RateLimitKey::of('login.account', 'anna@example.org');
        self::assertSame(
            [$key => [self::at('2026-11-17 12:00:00')->getTimestamp() => 2, self::at('2026-11-17 12:15:00')->getTimestamp() => 2]],
            $this->counters->all(),
        );
    }

    public function test_NFR_05_the_window_does_not_depend_on_the_time_zone_of_the_clock(): void
    {
        $limit = new RateLimit('login.account', 1, 3600);
        $this->limiter->consume($limit, 'x', new DateTimeImmutable('2026-11-17 13:30:00', new DateTimeZone('Europe/Berlin')));

        $key = RateLimitKey::of('login.account', 'x');
        self::assertSame(1, $this->counters->hits($key, self::at('2026-11-17 12:00:00')));
    }

    public function test_NFR_05_the_window_start_is_handed_to_the_counters_in_utc(): void
    {
        $counters = new RecordingRateLimitCounters();
        $limiter = new RateLimiter($counters, RateLimitKey::hasher());

        $limiter->consume(new RateLimit('a', 1, 900), 'x', new DateTimeImmutable('2026-11-17 13:07:00', new DateTimeZone('Europe/Berlin')));
        $limiter->refund(new RateLimit('a', 1, 900), 'x', new DateTimeImmutable('2026-11-17 13:07:00', new DateTimeZone('Europe/Berlin')));

        self::assertSame(['2026-11-17 12:00:00 UTC', '2026-11-17 12:00:00 UTC'], $counters->windowStarts);
    }

    public function test_NFR_05_subjects_and_rules_are_counted_apart(): void
    {
        $account = new RateLimit('login.account', 1, 900);
        $client = new RateLimit('login.client', 1, 900);
        $now = self::at('2026-11-17 12:00:00');

        $this->limiter->consume($account, 'anna@example.org', $now);
        $this->limiter->consume($account, 'bob@example.org', $now);
        // The same subject under another rule is another counter.
        $this->limiter->consume($client, 'anna@example.org', $now);

        self::assertCount(3, $this->counters->all());
    }

    public function test_NFR_05_the_stored_key_holds_a_hash_and_not_the_subject(): void
    {
        $this->limiter->consume(new RateLimit('mail.email', 3, 3600), 'anna@example.org', self::at('2026-11-17 12:00:00'));

        $keys = array_keys($this->counters->all());
        self::assertSame([RateLimitKey::of('mail.email', 'anna@example.org')], $keys);
        self::assertStringNotContainsString('anna', $keys[0]);
        // Fits the column of the rate_limits table whatever the subject is.
        self::assertLessThanOrEqual(191, strlen(RateLimitKey::of(str_repeat('a', 64), 'x')));
    }

    public function test_NFR_05_a_subject_can_be_counted_apart_for_every_place_it_is_tried_from(): void
    {
        // A rule about a pair: this email address, tried from this client address.
        $pair = new RateLimit('login.pair', 2, 900);
        $now = self::at('2026-11-17 12:00:00');
        $this->limiter->consume($pair, 'anna@example.org', $now, '203.0.113.1');
        $this->limiter->consume($pair, 'anna@example.org', $now, '203.0.113.1');

        // The same subject from elsewhere, and another subject from the same place, are other counters.
        $this->limiter->consume($pair, 'anna@example.org', $now, '203.0.113.2');
        $this->limiter->consume($pair, 'bob@example.org', $now, '203.0.113.1');
        // So is the subject without a place, and the pair the other way round.
        $this->limiter->consume($pair, 'anna@example.org', $now);
        $this->limiter->consume($pair, '203.0.113.1', $now, 'anna@example.org');
        self::assertTrue($this->limiter->tryConsume($pair, 'anna@example.org', $now, '203.0.113.2'));
        self::assertFalse($this->limiter->tryConsume($pair, 'anna@example.org', $now, '203.0.113.2'));

        try {
            $this->limiter->consume($pair, 'anna@example.org', $now, '203.0.113.1');
            self::fail('Expected the third hit of the pair to be refused.');
        } catch (RateLimitExceeded $refused) {
            self::assertSame(900, $refused->retryAfterSeconds);
        }
        self::assertSame(
            [
                RateLimitKey::of('login.pair', 'anna@example.org', '203.0.113.1') => 3,
                RateLimitKey::of('login.pair', 'anna@example.org', '203.0.113.2') => 3,
                RateLimitKey::of('login.pair', 'bob@example.org', '203.0.113.1') => 1,
                RateLimitKey::of('login.pair', 'anna@example.org') => 1,
                RateLimitKey::of('login.pair', '203.0.113.1', 'anna@example.org') => 1,
            ],
            array_map(array_sum(...), $this->counters->all()),
        );
    }

    public function test_NFR_05_a_refund_for_a_pair_takes_back_the_hit_of_that_pair_only(): void
    {
        $pair = new RateLimit('login.pair', 5, 900);
        $now = self::at('2026-11-17 12:00:00');
        $this->limiter->consume($pair, 'anna@example.org', $now, '203.0.113.1');
        $this->limiter->consume($pair, 'anna@example.org', $now, '203.0.113.2');
        $this->limiter->consume($pair, 'anna@example.org', $now);

        $this->limiter->refund($pair, 'anna@example.org', $now, '203.0.113.1');

        self::assertSame(
            [RateLimitKey::of('login.pair', 'anna@example.org', '203.0.113.2') => 1, RateLimitKey::of('login.pair', 'anna@example.org') => 1],
            array_map(array_sum(...), $this->counters->all()),
        );
    }

    public function test_NFR_05_the_key_of_a_pair_holds_two_hashes_ends_with_the_one_of_the_subject_and_fits_the_column(): void
    {
        $this->limiter->consume(new RateLimit('login.pair', 5, 900), 'anna@example.org', self::at('2026-11-17 12:00:00'), '203.0.113.1');

        $key = array_keys($this->counters->all())[0];
        // "<rule>:<keyed hash of the place, base64url>:<keyed hash of the subject, hexadecimal>".
        self::assertSame(RateLimitKey::of('login.pair', 'anna@example.org', '203.0.113.1'), $key);
        self::assertMatchesRegularExpression('/\Alogin\.pair:[A-Za-z0-9_-]{43}:[0-9a-f]{64}\z/', $key);
        self::assertStringNotContainsString('anna', $key);
        self::assertStringNotContainsString('203.0', $key);
        // With the longest name a rule can have, the key still fits VARCHAR(191) of rate_limits.
        self::assertLessThanOrEqual(191, strlen(RateLimitKey::of(str_repeat('a', 64), 'x', 'y')));
    }

    public function test_FR_ACC_08_forgetting_a_subject_removes_its_counters_of_every_place_it_was_tried_from(): void
    {
        // The key of a pair ends with the hash of the subject, so the counters of an email address
        // can be found and deleted without knowing the client addresses.
        $pair = new RateLimit('login.pair', 5, 900);
        $overall = new RateLimit('login.address', 50, 3600);
        $now = self::at('2026-11-17 12:00:00');
        $this->limiter->consume($pair, 'anna@example.org', $now, '203.0.113.1');
        $this->limiter->consume($pair, 'anna@example.org', $now, '203.0.113.2');
        $this->limiter->consume($overall, 'anna@example.org', $now);
        $this->limiter->consume($pair, 'bob@example.org', $now, '203.0.113.1');
        // A client address is no subject here: the pairs it is the place of stay.
        $this->limiter->consume(new RateLimit('login.client', 30, 900), '203.0.113.1', $now);

        $this->limiter->forget('anna@example.org');
        $this->limiter->forget('203.0.113.2');

        self::assertSame(
            [RateLimitKey::of('login.pair', 'bob@example.org', '203.0.113.1'), RateLimitKey::of('login.client', '203.0.113.1')],
            array_keys($this->counters->all()),
        );
    }

    public function test_NFR_05_resetting_a_rule_for_a_subject_leaves_its_pairs_alone(): void
    {
        $pair = new RateLimit('login.pair', 5, 900);
        $now = self::at('2026-11-17 12:00:00');
        $this->limiter->consume($pair, 'anna@example.org', $now, '203.0.113.1');
        $this->limiter->consume($pair, 'anna@example.org', $now);

        $this->limiter->reset($pair, 'anna@example.org');

        self::assertSame([RateLimitKey::of('login.pair', 'anna@example.org', '203.0.113.1')], array_keys($this->counters->all()));
    }

    public function test_NFR_05_a_refused_hit_stays_counted(): void
    {
        $limit = new RateLimit('login.account', 1, 900);
        $now = self::at('2026-11-17 12:00:00');
        $this->limiter->consume($limit, 'x', $now);

        try {
            $this->limiter->consume($limit, 'x', $now);
        } catch (RateLimitExceeded) {
        }

        self::assertSame(2, $this->counters->hits(RateLimitKey::of('login.account', 'x'), $now));
    }

    public function test_NFR_05_a_refund_takes_back_one_hit_of_the_same_window_only(): void
    {
        $limit = new RateLimit('login.account', 5, 900);
        $key = RateLimitKey::of('login.account', 'x');
        $this->limiter->consume($limit, 'x', self::at('2026-11-17 12:01:00'));
        $this->limiter->consume($limit, 'x', self::at('2026-11-17 12:02:00'));
        $this->limiter->consume($limit, 'x', self::at('2026-11-17 12:16:00'));

        $this->limiter->refund($limit, 'x', self::at('2026-11-17 12:14:59'));

        self::assertSame(1, $this->counters->hits($key, self::at('2026-11-17 12:00:00')));
        self::assertSame(1, $this->counters->hits($key, self::at('2026-11-17 12:15:00')));

        // Refunding more than was counted does not create credit.
        $this->limiter->refund($limit, 'x', self::at('2026-11-17 12:00:00'));
        $this->limiter->refund($limit, 'x', self::at('2026-11-17 12:00:00'));
        self::assertSame(0, $this->counters->hits($key, self::at('2026-11-17 12:00:00')));
        $this->limiter->refund($limit, 'y', self::at('2026-11-17 12:00:00'));
        self::assertSame(1, $this->counters->hits($key, self::at('2026-11-17 12:15:00')));
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function invalidRules(): iterable
    {
        yield 'no name' => ['', 1, 1];
        yield 'upper case' => ['Login', 1, 1];
        yield 'with a colon (the separator of the key)' => ['login:account', 1, 1];
        yield 'with a digit' => ['login2', 1, 1];
        yield 'trailing dot' => ['login.', 1, 1];
        yield 'leading dot' => ['.login', 1, 1];
        yield 'line break' => ["login\n", 1, 1];
        yield 'name of 65 characters' => [str_repeat('a', 65), 1, 1];
        yield 'no hits allowed' => ['login', 0, 1];
        yield 'negative hits' => ['login', -1, 1];
        yield 'no window' => ['login', 1, 0];
        yield 'negative window' => ['login', 1, -1];
    }

    #[DataProvider('invalidRules')]
    public function test_a_rule_needs_a_name_a_limit_and_a_window(string $name, int $maxHits, int $windowSeconds): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RateLimit($name, $maxHits, $windowSeconds);
    }

    public function test_the_smallest_rule_is_valid(): void
    {
        $limit = new RateLimit(str_repeat('a', 64), 1, 1);

        self::assertSame([str_repeat('a', 64), 1, 1], [$limit->name, $limit->maxHits, $limit->windowSeconds]);
        self::assertSame('a.b', (new RateLimit('a.b', 1, 1))->name);
    }

    public function test_FR_ACC_08_forgetting_a_subject_removes_its_counters_of_every_rule_and_window(): void
    {
        $login = new RateLimit('login.account', 5, 900);
        $mails = new RateLimit('mail.email', 3, 3600);
        $this->limiter->consume($login, 'anna@example.org', self::at('2026-11-17 12:00:00'));
        $this->limiter->consume($login, 'anna@example.org', self::at('2026-11-17 12:20:00'));
        $this->limiter->consume($mails, 'anna@example.org', self::at('2026-11-17 12:00:00'));
        $this->limiter->consume($login, 'bob@example.org', self::at('2026-11-17 12:00:00'));

        $this->limiter->forget('anna@example.org');

        self::assertSame(
            [RateLimitKey::of('login.account', 'bob@example.org')],
            array_keys($this->counters->all()),
        );
    }

    public function test_NFR_05_no_key_holds_an_address_or_a_plain_hash_of_one(): void
    {
        // Whoever can read the table (a backup, an SQL injection) must not be able to test whether
        // a given email or client address is in it. A plain SHA-256 would allow exactly that.
        $this->limiter->consume(new RateLimit('login.pair', 5, 900), 'anna@example.org', self::at('2026-11-17 12:00:00'), '203.0.113.1');
        $this->limiter->consume(new RateLimit('login.client', 30, 900), '203.0.113.1', self::at('2026-11-17 12:00:00'));
        $this->limiter->consume(new RateLimit('login.address', 50, 3600), 'anna@example.org', self::at('2026-11-17 12:00:00'));

        $keys = array_keys($this->counters->all());
        self::assertCount(3, $keys);
        $all = implode(' ', $keys);
        foreach (['anna@example.org', '203.0.113.1'] as $value) {
            self::assertStringNotContainsString($value, $all);
            self::assertStringNotContainsString(hash('sha256', $value), $all);
            self::assertStringNotContainsString(rtrim(strtr(base64_encode(hash('sha256', $value, true)), '+/', '-_'), '='), $all);
        }
        self::assertEqualsCanonicalizing(
            [
                RateLimitKey::of('login.pair', 'anna@example.org', '203.0.113.1'),
                RateLimitKey::of('login.client', '203.0.113.1'),
                RateLimitKey::of('login.address', 'anna@example.org'),
            ],
            $keys,
        );
    }

    public function test_NFR_05_every_part_of_a_key_is_made_by_the_hasher_the_limiter_was_given(): void
    {
        // 32 bytes like the real one, and readable: the value behind a "#", filled up with dots.
        $hasher = new class () implements RateLimitKeyHasher {
            public function hash(string $value): string
            {
                return str_pad('#' . $value, 32, '.');
            }
        };
        $counters = new RecordingRateLimitCounters();
        $limiter = new RateLimiter($this->counters, $hasher);

        $limiter->consume(new RateLimit('mail.email', 5, 3600), 'a@b.example', self::at('2026-11-17 12:00:00'));
        $limiter->consume(new RateLimit('mail.pair', 3, 3600), 'a@b.example', self::at('2026-11-17 12:00:00'), '203.0.113.9');

        $subject = bin2hex(str_pad('#a@b.example', 32, '.'));
        $place = rtrim(strtr(base64_encode(str_pad('#203.0.113.9', 32, '.')), '+/', '-_'), '=');
        self::assertEqualsCanonicalizing(['mail.email:' . $subject, "mail.pair:{$place}:" . $subject], array_keys($this->counters->all()));

        // Account deletion looks for the same hash.
        (new RateLimiter($counters, $hasher))->forget('a@b.example');
        self::assertSame([':' . $subject], $counters->deletedSuffixes);
    }

    public function test_FR_ACC_08_a_subject_is_forgotten_by_the_whole_hash_part_of_its_keys(): void
    {
        // With the separator in front: a key is "<rule>:<hash>", and only a whole hash is a subject.
        $counters = new RecordingRateLimitCounters();

        (new RateLimiter($counters, RateLimitKey::hasher()))->forget('anna@example.org');

        self::assertSame([':' . RateLimitKey::subjectHash('anna@example.org')], $counters->deletedSuffixes);
    }

    public function test_FR_ACC_08_forgetting_a_subject_that_was_never_counted_does_nothing(): void
    {
        $this->limiter->consume(new RateLimit('login.account', 5, 900), 'bob@example.org', self::at('2026-11-17 12:00:00'));

        $this->limiter->forget('anna@example.org');

        self::assertCount(1, $this->counters->all());
    }

    private static function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }
}
