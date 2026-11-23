<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Auth;

use SmokeDetector\Application\RateLimit\RateLimitExceeded;

/**
 * The three limits on failed password checks (HLD §4.5 "Rate limits", NFR-05): they lock the one
 * who guesses, not the account.
 */
final class FailedLoginsTest extends AuthUseCaseTestCase
{
    private const string STRANGER = '198.51.100.66';

    public function test_NFR_05_an_attempt_is_counted_for_the_client_for_the_pair_and_for_the_address(): void
    {
        $this->failedLogins()->count(self::email(), self::CLIENT, $this->now());

        self::assertSame(
            ['client' => 1, 'pair' => 1, 'address' => 1],
            $this->failedLoginsCounted('anna@example.org', self::CLIENT),
        );
        self::assertCount(3, $this->counters->all());
    }

    public function test_NFR_05_taking_an_attempt_back_takes_back_its_three_counts_and_nothing_else(): void
    {
        $failedLogins = $this->failedLogins();
        $failedLogins->count(self::email(), self::CLIENT, $this->now());
        $failedLogins->count(self::email(), self::CLIENT, $this->now());
        $failedLogins->count(self::email(), self::STRANGER, $this->now());
        $failedLogins->count(self::email('bob@example.org'), self::CLIENT, $this->now());

        $failedLogins->takeBack(self::email(), self::CLIENT, $this->now());

        self::assertSame(['client' => 2, 'pair' => 1, 'address' => 2], $this->failedLoginsCounted('anna@example.org', self::CLIENT));
        self::assertSame(['client' => 1, 'pair' => 1, 'address' => 2], $this->failedLoginsCounted('anna@example.org', self::STRANGER));
        self::assertSame(['client' => 2, 'pair' => 1, 'address' => 1], $this->failedLoginsCounted('bob@example.org', self::CLIENT));
    }

    public function test_NFR_05_the_sixth_attempt_for_an_address_from_one_client_within_15_minutes_is_refused(): void
    {
        $failedLogins = $this->failedLogins();
        for ($i = 1; $i <= 5; $i++) {
            $failedLogins->count(self::email(), self::STRANGER, $this->now());
        }
        $this->clock->advanceSeconds(899);

        $refused = $this->refusal(fn () => $failedLogins->count(self::email(), self::STRANGER, $this->now()));

        self::assertSame(1, $refused->retryAfterSeconds);
        // The stranger is locked for this address. The address is not: its owner, anywhere else,
        // is not affected, and neither are the stranger's attempts at other addresses.
        $failedLogins->count(self::email(), self::CLIENT, $this->now());
        $failedLogins->count(self::email('bob@example.org'), self::STRANGER, $this->now());
        // The refused attempt was not held against the address: the stranger cannot use up its
        // budget by going on.
        self::assertSame(['client' => 7, 'pair' => 6, 'address' => 6], $this->failedLoginsCounted('anna@example.org', self::STRANGER));
        // After the quarter of an hour the pair is free again.
        $this->clock->advanceSeconds(1);
        $failedLogins->count(self::email(), self::STRANGER, $this->now());
    }

    public function test_NFR_05_the_31st_attempt_from_one_client_within_15_minutes_is_refused_whatever_the_address(): void
    {
        $failedLogins = $this->failedLogins();
        // Six addresses, five attempts each: no pair is over its limit, the client is.
        for ($i = 0; $i < 30; $i++) {
            $failedLogins->count(self::email('user' . ($i % 6) . '@example.org'), self::STRANGER, $this->now());
        }

        $refused = $this->refusal(fn () => $failedLogins->count(self::email(), self::STRANGER, $this->now()));

        self::assertSame(900, $refused->retryAfterSeconds);
        // A client over its own limit uses up nobody else's budget.
        self::assertSame(['client' => 31, 'pair' => 0, 'address' => 0], $this->failedLoginsCounted('anna@example.org', self::STRANGER));
        $failedLogins->count(self::email(), self::CLIENT, $this->now());
        $this->clock->advanceSeconds(900);
        $failedLogins->count(self::email(), self::STRANGER, $this->now());
    }

    public function test_NFR_05_the_51st_attempt_for_an_address_within_an_hour_is_refused_for_everybody(): void
    {
        $failedLogins = $this->failedLogins();
        $this->clock->advanceSeconds(600);
        for ($i = 1; $i <= 50; $i++) {
            $failedLogins->count(self::email(), "198.51.100.{$i}", $this->now());
        }

        // From a client that never tried before.
        $refused = $this->refusal(fn () => $failedLogins->count(self::email(), self::CLIENT, $this->now()));

        // It is 12:10, the hour ends at 13:00.
        self::assertSame(3000, $refused->retryAfterSeconds);
        self::assertSame(['client' => 1, 'pair' => 1, 'address' => 51], $this->failedLoginsCounted('anna@example.org', self::CLIENT));
        // Another address is not affected, and after the hour this one is free again.
        $failedLogins->count(self::email('bob@example.org'), self::CLIENT, $this->now());
        $this->clock->advanceSeconds(2999);
        $this->refusal(fn () => $failedLogins->count(self::email(), '203.0.113.200', $this->now()));
        $this->clock->advanceSeconds(1);
        $failedLogins->count(self::email(), '203.0.113.201', $this->now());
    }

    public function test_NFR_05_the_overall_count_of_an_address_can_be_cleared_alone(): void
    {
        $failedLogins = $this->failedLogins();
        $failedLogins->count(self::email(), self::CLIENT, $this->now());
        $failedLogins->count(self::email('bob@example.org'), self::CLIENT, $this->now());

        $failedLogins->clearAddress(self::email());

        self::assertSame(['client' => 2, 'pair' => 1, 'address' => 0], $this->failedLoginsCounted('anna@example.org', self::CLIENT));
        self::assertSame(['client' => 2, 'pair' => 1, 'address' => 1], $this->failedLoginsCounted('bob@example.org', self::CLIENT));
    }

    /**
     * @param callable(): void $attempt
     */
    private function refusal(callable $attempt): RateLimitExceeded
    {
        try {
            $attempt();
        } catch (RateLimitExceeded $refused) {
            return $refused;
        }

        self::fail('Expected the attempt to be refused.');
    }
}
