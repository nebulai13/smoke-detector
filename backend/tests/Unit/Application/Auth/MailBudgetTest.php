<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Auth;

use SmokeDetector\Application\Auth\AuthLimits;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;

/**
 * How many mails a stranger can make us send (HLD §4.5 "Rate limits", NFR-05): registration, "send
 * the link again" and the reset request share one budget.
 */
final class MailBudgetTest extends AuthUseCaseTestCase
{
    public function test_NFR_05_a_request_is_counted_for_the_client_for_the_pair_and_for_the_address(): void
    {
        self::assertTrue($this->mailBudget()->spend(self::email(), self::CLIENT, $this->now()));

        self::assertSame(['client' => 1, 'pair' => 1, 'address' => 1], $this->counted('anna@example.org', self::CLIENT));
        self::assertCount(3, $this->counters->all());
    }

    public function test_NFR_05_the_sixth_request_from_one_client_within_an_hour_is_refused(): void
    {
        $budget = $this->mailBudget();
        $this->clock->advanceSeconds(600);
        for ($i = 1; $i <= 5; $i++) {
            self::assertTrue($budget->spend(self::email("user{$i}@example.org"), self::CLIENT, $this->now()));
        }

        $refused = $this->refusal(fn () => $budget->spend(self::email(), self::CLIENT, $this->now()));

        // It is 12:10, the hour ends at 13:00.
        self::assertSame(3000, $refused->retryAfterSeconds);
        // A client over its own limit uses up nobody else's budget.
        self::assertSame(['client' => 6, 'pair' => 0, 'address' => 0], $this->counted('anna@example.org', self::CLIENT));
        self::assertTrue($budget->spend(self::email(), '198.51.100.1', $this->now()));
        $this->clock->advanceSeconds(3000);
        self::assertTrue($budget->spend(self::email(), self::CLIENT, $this->now()));
    }

    public function test_NFR_05_the_fourth_request_for_an_address_from_one_client_within_an_hour_is_refused(): void
    {
        $budget = $this->mailBudget();
        for ($i = 1; $i <= 3; $i++) {
            self::assertTrue($budget->spend(self::email(), self::CLIENT, $this->now()));
        }

        $refused = $this->refusal(fn () => $budget->spend(self::email(), self::CLIENT, $this->now()));

        self::assertSame(3600, $refused->retryAfterSeconds);
        // Not held against the address, and nobody else is kept from asking for a mail to it.
        self::assertSame(['client' => 4, 'pair' => 4, 'address' => 3], $this->counted('anna@example.org', self::CLIENT));
        self::assertTrue($budget->spend(self::email(), '198.51.100.1', $this->now()));
        // The client can still ask for a mail to another address.
        self::assertTrue($budget->spend(self::email('bob@example.org'), self::CLIENT, $this->now()));
    }

    public function test_NFR_05_the_eleventh_mail_to_an_address_within_an_hour_is_left_out_and_nothing_is_refused(): void
    {
        $budget = $this->mailBudget();
        // Ten requests from ten clients: no client and no pair is anywhere near its limit.
        for ($i = 1; $i <= 10; $i++) {
            self::assertTrue($budget->spend(self::email(), "198.51.100.{$i}", $this->now()), "request {$i}");
        }

        // No exception: a 429 here would keep the owner from asking, because of what others did.
        self::assertFalse($budget->spend(self::email(), self::CLIENT, $this->now()));
        self::assertFalse($budget->spend(self::email(), self::CLIENT, $this->now()));

        self::assertSame(['client' => 2, 'pair' => 2, 'address' => 12], $this->counted('anna@example.org', self::CLIENT));
        // Another address gets its mail, and after the hour this one does again.
        self::assertTrue($budget->spend(self::email('bob@example.org'), self::CLIENT, $this->now()));
        $this->clock->advanceSeconds(3599);
        self::assertFalse($budget->spend(self::email(), '203.0.113.200', $this->now()));
        $this->clock->advanceSeconds(1);
        self::assertTrue($budget->spend(self::email(), '203.0.113.201', $this->now()));
    }

    /**
     * @return array{client: int, pair: int, address: int}
     */
    private function counted(string $email, string $client): array
    {
        return [
            'client' => $this->hits(AuthLimits::mailsPerClient(), $client),
            'pair' => $this->hits(AuthLimits::mailsPerPair(), $email, $client),
            'address' => $this->hits(AuthLimits::mailsPerAddress(), $email),
        ];
    }

    /**
     * @param callable(): mixed $request
     */
    private function refusal(callable $request): RateLimitExceeded
    {
        try {
            $request();
        } catch (RateLimitExceeded $refused) {
            return $refused;
        }

        self::fail('Expected the request to be refused.');
    }
}
