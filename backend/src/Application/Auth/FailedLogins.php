<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use DateTimeImmutable;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\RateLimit\RateLimiter;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;

/**
 * The limits on failed password checks for an email address: login and account deletion (HLD §4.5
 * "Rate limits", NFR-05). They lock the one who guesses, not the account.
 *
 * Three rules, checked in this order (AuthLimits):
 *
 * 1. the client address, over all email addresses: 30 failures per 15 minutes,
 * 2. the pair of email address and client address: 5 failures per 15 minutes,
 * 3. the email address, over all client addresses: 50 failures per hour.
 *
 * A refusal stops at the first rule that is exceeded, and the rules after it are not counted. So
 * a client over its own limit, or over its limit for one address, uses up nobody else's budget:
 * five wrong guesses lock that client for that address, and the owner of the address logs in from
 * anywhere else. Only guessing from many client addresses reaches the third rule, which locks the
 * address for everybody until the hour is over.
 *
 * Every attempt is counted before the password is looked at and taken back only when it succeeded.
 * So attempts that arrive at the same moment cannot outrun the counters, and a success takes back
 * nothing but its own count. An address nobody has is counted exactly like one that is registered:
 * a limit says nothing about who has an account.
 */
final readonly class FailedLogins
{
    public function __construct(private RateLimiter $limiter)
    {
    }

    /**
     * Counts one attempt. Call it before the password is checked, outside the transaction.
     *
     * @throws RateLimitExceeded When this attempt is one too many for the client, for the client
     *                           at this address, or for the address. Whatever the password is.
     */
    public function count(EmailAddress $email, string $clientAddress, DateTimeImmutable $now): void
    {
        $this->limiter->consume(AuthLimits::loginFailuresPerClient(), $clientAddress, $now);
        $this->limiter->consume(AuthLimits::loginFailuresPerPair(), $email->value, $now, $clientAddress);
        $this->limiter->consume(AuthLimits::loginFailuresPerAddress(), $email->value, $now);
    }

    /**
     * Takes back the attempt that count() counted with the same arguments: it was not a failure.
     */
    public function takeBack(EmailAddress $email, string $clientAddress, DateTimeImmutable $now): void
    {
        $this->limiter->refund(AuthLimits::loginFailuresPerAddress(), $email->value, $now);
        $this->limiter->refund(AuthLimits::loginFailuresPerPair(), $email->value, $now, $clientAddress);
        $this->limiter->refund(AuthLimits::loginFailuresPerClient(), $clientAddress, $now);
    }

    /**
     * Forgets the failures counted for the address over all clients (the third rule), after a
     * password reset: they were guesses at a password that no longer exists. What one client
     * guessed at the address stays counted for that client.
     */
    public function clearAddress(EmailAddress $email): void
    {
        $this->limiter->reset(AuthLimits::loginFailuresPerAddress(), $email->value);
    }
}
