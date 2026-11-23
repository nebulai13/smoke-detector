<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use DateTimeImmutable;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\RateLimit\RateLimiter;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;

/**
 * How many mails anybody can make us send to an address: registration, "send the confirmation
 * link again" and the password reset request share this budget (HLD §4.5 "Rate limits", NFR-05).
 *
 * Three rules, checked in this order (AuthLimits):
 *
 * 1. the client address, over all email addresses: 5 requests per hour, then 429,
 * 2. the pair of email address and client address: 3 requests per hour, then 429,
 * 3. the email address, over all client addresses: 10 mails per hour. Over that nothing is
 *    refused: the request is answered as always, and no mail goes out.
 *
 * As with FailedLogins, a client is refused by what it did itself, never by what others did to the
 * same address. The third rule protects the mailbox from a flood out of many client addresses. It
 * cannot be a 429: that would keep the owner from registering or asking for a reset exactly when
 * somebody is flooding the address. Requests are counted, whether or not one leads to a mail, so
 * the budget of an address nobody has is used up exactly like that of a registered one.
 */
final readonly class MailBudget
{
    public function __construct(private RateLimiter $limiter)
    {
    }

    /**
     * Counts one request that would mail the address. Call it outside the transaction.
     *
     * @return bool False when the address has had its mails for this hour: the use case then changes
     *              nothing and sends nothing, and answers like every other time. Changing nothing
     *              matters: a new link without its mail would only make the link of the last mail
     *              that did go out stop working.
     *
     * @throws RateLimitExceeded When the client has asked too often, in all or for this address.
     */
    public function spend(EmailAddress $to, string $clientAddress, DateTimeImmutable $now): bool
    {
        $this->limiter->consume(AuthLimits::mailsPerClient(), $clientAddress, $now);
        $this->limiter->consume(AuthLimits::mailsPerPair(), $to->value, $now, $clientAddress);

        return $this->limiter->tryConsume(AuthLimits::mailsPerAddress(), $to->value, $now);
    }
}
