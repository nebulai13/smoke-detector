<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use SmokeDetector\Application\RateLimit\RateLimit;

/**
 * The rate limits of HLD §4.5 (NFR-05).
 *
 * The principle: lock the one who guesses, not the account. What one client does to an email
 * address is counted for that pair, and the tight limit is the pair's. The limits over all
 * addresses (per client) and over all clients (per address) are far higher, so that a shared
 * network is not locked by one person's typos and a stranger with one client address cannot lock
 * the owner of an address out. A stranger with a few client addresses can (SRS OQ-15): see
 * loginFailuresPerAddress() and mailsPerAddress(). FailedLogins and MailBudget apply the rules in
 * their order.
 */
final readonly class AuthLimits
{
    /** Failed password checks from one client address, against whatever addresses: 30 per 15 minutes. */
    public static function loginFailuresPerClient(): RateLimit
    {
        return new RateLimit('login.client', 30, 900);
    }

    /** Failed password checks for one email address from one client address: 5 per 15 minutes. */
    public static function loginFailuresPerPair(): RateLimit
    {
        return new RateLimit('login.pair', 5, 900);
    }

    /**
     * Failed password checks for one email address, from wherever: 50 per hour. The one rule that
     * locks an address for everybody, against guessing from many client addresses at once. It
     * takes few: 3 client addresses reach the 50 within the hour (20 each at 5 per 15 minutes),
     * and 10 reach it at once, which locks the owner out of login and account deletion for the
     * rest of the hour (SRS OQ-15). A successful password reset clears it.
     */
    public static function loginFailuresPerAddress(): RateLimit
    {
        return new RateLimit('login.address', 50, 3600);
    }

    /**
     * Requests from one client address that make us send a mail: 5 per hour. Registration, resending
     * the confirmation and the password reset request count together, because the limit is about the
     * mails a stranger can make us send, and each of the three sends one.
     */
    public static function mailsPerClient(): RateLimit
    {
        return new RateLimit('mail.client', 5, 3600);
    }

    /** The same requests for one email address from one client address: 3 per hour. */
    public static function mailsPerPair(): RateLimit
    {
        return new RateLimit('mail.pair', 3, 3600);
    }

    /**
     * The same requests for one email address, from wherever: 10 per hour. Never the reason for a
     * 429: over it the request is answered as always and the mail is left out, so the answer says
     * nothing. That still costs the owner: once 10 mails went out in the hour (4 client addresses
     * suffice), the owner's own reset mail is left out too, until the hour is over (SRS OQ-15).
     */
    public static function mailsPerAddress(): RateLimit
    {
        return new RateLimit('mail.address', 10, 3600);
    }

    /**
     * Confirmation mails that one client address causes by logging in with the right password
     * before the address is confirmed (see LogIn): 5 per hour.
     *
     * A rule of its own, and never the reason for a 429: over it the mail is left out silently.
     * It must not share the counter of mailsPerClient(), which registration, resend and the reset
     * request refuse by. Only this one kind of failed login sends a mail, so a counter that moves
     * with it and is then answered with 429 elsewhere would tell this kind of login from the others.
     */
    public static function confirmationMailsByLoginPerClient(): RateLimit
    {
        return new RateLimit('login.mail.client', 5, 3600);
    }

    /** The same mails to one email address: 1 per hour, on top of the mail budget. */
    public static function confirmationMailsByLoginPerEmail(): RateLimit
    {
        return new RateLimit('login.mail.email', 1, 3600);
    }
}
