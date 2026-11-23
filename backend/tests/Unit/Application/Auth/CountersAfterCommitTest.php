<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Auth;

use LogicException;
use RuntimeException;
use SmokeDetector\Application\Auth\Session;

/**
 * Rate-limit counters are not part of any transaction (HLD §4.5 "Errors"). A use case takes a
 * counted attempt back, or clears counters, after its own work is committed. If the database fails
 * at that moment, the work is done all the same: answering "try again later" would be wrong, and
 * for a password reset the second try could not even succeed, because the link is used up.
 */
final class CountersAfterCommitTest extends AuthUseCaseTestCase
{
    private const string WARNING = 'A rate limit counter could not be updated after the work was done.';

    public function test_NFR_05_a_login_stands_when_its_counted_attempt_cannot_be_taken_back(): void
    {
        $this->givenUser();
        $this->counters->failDecrement = new RuntimeException('The database went away while counting for anna@example.org.');

        $session = $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT);

        self::assertInstanceOf(Session::class, $session);
        self::assertCount(1, $this->refreshTokens->all());
        $this->assertOneWarning(RuntimeException::class);
    }

    public function test_NFR_05_a_confirmation_stands_when_its_counted_attempt_cannot_be_taken_back(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        $this->counters->failDecrement = new RuntimeException('The database went away while counting for anna@example.org.');

        $session = $this->confirm('token-1');

        self::assertInstanceOf(Session::class, $session);
        self::assertCount(1, $this->refreshTokens->all());
        // Confirmed: the account can be logged in to.
        $this->counters->failDecrement = null;
        self::assertInstanceOf(Session::class, $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT));
        $this->assertOneWarning(RuntimeException::class);
    }

    public function test_FR_ACC_05_a_password_reset_stands_when_the_failed_logins_of_the_address_cannot_be_cleared(): void
    {
        $this->givenUser();
        $this->requestPasswordReset()->execute(self::email(), self::CLIENT);
        $this->counters->failDeleteKey = new RuntimeException('The database went away while counting for anna@example.org.');

        // No failure reaches the caller. A second try with the same link would be "invalid token".
        $this->resetPassword()->execute('token-1', 'a brand new password');

        $this->assertOneWarning(RuntimeException::class);
        $this->counters->failDeleteKey = null;
        self::assertInstanceOf(Session::class, $this->logIn()->execute(self::email(), 'a brand new password', null, self::CLIENT));
    }

    public function test_NFR_05_a_programming_error_in_the_counter_work_is_not_swallowed(): void
    {
        $this->givenUser();
        $failure = new LogicException('The counters were used inside a transaction.');
        $this->counters->failDecrement = $failure;

        try {
            $this->logIn()->execute(self::email(), self::PASSWORD, null, self::CLIENT);
            self::fail('Expected the failure to be passed on.');
        } catch (LogicException $passedOn) {
            self::assertSame($failure, $passedOn);
        }
    }

    /**
     * @param class-string $exception
     */
    private function assertOneWarning(string $exception): void
    {
        self::assertSame(
            [['level' => 'warning', 'message' => self::WARNING, 'context' => ['exception' => $exception]]],
            $this->logger->records,
        );
        // The message of the failure names the address. Only its class is logged.
        self::assertStringNotContainsString('anna', $this->logger->text());
    }
}
