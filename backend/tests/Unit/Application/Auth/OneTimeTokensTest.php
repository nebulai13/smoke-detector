<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use SmokeDetector\Application\Auth\InvalidToken;
use SmokeDetector\Application\Auth\OneTimeToken;
use SmokeDetector\Application\Auth\OneTimeTokens;
use SmokeDetector\Application\Auth\TokenPurpose;
use SmokeDetector\Tests\Support\TestData;

/**
 * The tries of a confirmation link (HLD §4.5 "Registration" step 5): what VerifyEmail relies on
 * when the link changes between its first look and the moment it holds the lock.
 */
final class OneTimeTokensTest extends AuthUseCaseTestCase
{
    /**
     * @return iterable<string, array{OneTimeToken|null}>
     */
    public static function tokensWithoutATryLeft(): iterable
    {
        $user = TestData::userId(1);

        yield 'gone' => [null];
        yield 'used' => [TestData::oneTimeToken(10, $user, 'link', usedAt: '2026-11-17 11:59:00')];
        yield 'expired this second' => [TestData::oneTimeToken(10, $user, 'link', expiresAt: '2026-11-17 12:00:00')];
        yield 'with five tries on it' => [TestData::oneTimeToken(10, $user, 'link', failedAttempts: 5)];
        yield 'for another purpose' => [TestData::oneTimeToken(10, $user, 'link', TokenPurpose::ResetPassword)];
    }

    #[DataProvider('tokensWithoutATryLeft')]
    public function test_NFR_05_a_try_on_a_link_that_has_none_left_is_refused_and_not_counted(?OneTimeToken $stored): void
    {
        if ($stored !== null) {
            $this->oneTimeTokens->put($stored);
        }
        $before = $this->oneTimeTokens->all();

        try {
            $this->transactions->run(fn (): bool => $this->oneTimeTokensService()->countAttempt(TokenPurpose::VerifyEmail, 'link', $this->now()));
            self::fail('Expected the try to be refused.');
        } catch (InvalidToken) {
        }

        self::assertEquals($before, $this->oneTimeTokens->all());
        self::assertNull($this->oneTimeTokensService()->ownerOfUsable(TokenPurpose::VerifyEmail, 'link', $this->now()));
    }

    public function test_NFR_05_the_tries_of_a_link_are_counted_one_by_one_and_the_fifth_is_said_to_be_the_last(): void
    {
        $user = TestData::userId(1);
        $this->oneTimeTokens->put(TestData::oneTimeToken(10, $user, 'link', expiresAt: '2026-11-17 12:00:01'));
        $tokens = $this->oneTimeTokensService();

        $last = [];
        for ($try = 1; $try <= OneTimeTokens::MAX_FAILED_ATTEMPTS; $try++) {
            self::assertTrue($tokens->ownerOfUsable(TokenPurpose::VerifyEmail, 'link', $this->now())?->equals($user));
            $last[] = $this->transactions->run(fn (): bool => $tokens->countAttempt(TokenPurpose::VerifyEmail, 'link', $this->now()));
            self::assertSame($try, $this->oneTimeTokens->all()[0]->failedAttempts);
        }

        self::assertSame(5, OneTimeTokens::MAX_FAILED_ATTEMPTS);
        self::assertSame([false, false, false, false, true], $last);
        self::assertNull($tokens->ownerOfUsable(TokenPurpose::VerifyEmail, 'link', $this->now()));
        // A link with all its tries used can still be redeemed: by the fifth try, if it was right.
        self::assertTrue($this->transactions->run(fn () => $tokens->redeem(TokenPurpose::VerifyEmail, 'link', $this->now()))->equals($user));
    }

    public function test_NFR_05_withdrawing_a_link_deletes_that_link_only(): void
    {
        $this->oneTimeTokens->put(TestData::oneTimeToken(10, TestData::userId(1), 'link'));
        $this->oneTimeTokens->put(TestData::oneTimeToken(11, TestData::userId(1), 'reset', TokenPurpose::ResetPassword));
        $this->oneTimeTokens->put(TestData::oneTimeToken(12, TestData::userId(2), 'other'));
        $tokens = $this->oneTimeTokensService();

        $this->transactions->run(function () use ($tokens): void {
            $tokens->withdraw(TokenPurpose::VerifyEmail, 'link');
            // Not there (any more), or of another purpose: nothing happens.
            $tokens->withdraw(TokenPurpose::VerifyEmail, 'link');
            $tokens->withdraw(TokenPurpose::VerifyEmail, 'reset');
        });

        self::assertFalse($this->hasOneTimeToken('link', TokenPurpose::VerifyEmail));
        self::assertTrue($this->hasOneTimeToken('reset', TokenPurpose::ResetPassword));
        self::assertTrue($this->hasOneTimeToken('other', TokenPurpose::VerifyEmail));
    }
}
