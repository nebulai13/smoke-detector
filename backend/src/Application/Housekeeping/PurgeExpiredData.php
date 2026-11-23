<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Housekeeping;

use DateInterval;
use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\Auth\OneTimeTokenRepository;
use SmokeDetector\Application\Auth\RefreshTokenRepository;
use SmokeDetector\Application\Mail\MailKind;
use SmokeDetector\Application\Mail\MailOutbox;
use SmokeDetector\Application\RateLimit\RateLimitCounters;
use SmokeDetector\Application\TransactionRunner;

/**
 * Deletes what is no longer needed (HLD §4.5 "Registration" step 7, §5.2). For the cron job, which
 * calls it as `php bin/console housekeeping:purge`: it can run as often as wanted, and a run that
 * is interrupted has deleted some of it and leaves the rest to the next run.
 *
 * - Registrations that were not confirmed within 24 hours, with their consents, their tokens and
 *   their mails that still wait to be sent (FR-ACC-03).
 * - One-time tokens that are expired or used.
 * - Sessions (refresh tokens) that are expired or revoked.
 * - Rate limit counters of windows that are long over.
 * - Mails of the outbox that were queued more than 24 hours ago, whatever their template. The
 *   sender drops a mail whose link has expired when it takes it, but it only takes mails whose
 *   template the running release knows: after a rollback, the mails of the newer release would
 *   otherwise wait for ever, address and all.
 */
final readonly class PurgeExpiredData
{
    /** FR-ACC-03: how long a registration may stay unconfirmed. */
    public const int UNCONFIRMED_REGISTRATION_LIFETIME_SECONDS = User::UNCONFIRMED_LIFETIME_SECONDS;

    /** Twice the longest window any rule may have (a day), so a counter in use is never deleted. */
    public const int RATE_LIMIT_RETENTION_SECONDS = 172_800;

    /** How long a queued mail is kept at most: as long as the longest-lived link in a mail works. */
    public const int QUEUED_MAIL_LIFETIME_SECONDS = MailKind::LONGEST_LIFETIME_SECONDS;

    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private OneTimeTokenRepository $oneTimeTokens,
        private RefreshTokenRepository $refreshTokens,
        private RateLimitCounters $rateLimitCounters,
        private MailOutbox $outbox,
    ) {
    }

    public function execute(): PurgeReport
    {
        $now = $this->clock->now();

        // A transaction for each table, not one: the cleanup never holds locks in two tables at once, so it
        // cannot wait for a request that locks them in another order while that request waits for
        // it. (Deleting a registration takes its consents and tokens along. That is the order every
        // use case locks in: the account first.) Each deletion stands for itself, and whatever a
        // run does not get to, the next one does.
        $registrations = $this->transactions->run(fn (): int => $this->users->deleteUnverifiedCreatedBefore(
            $now->sub(new DateInterval('PT' . self::UNCONFIRMED_REGISTRATION_LIFETIME_SECONDS . 'S')),
        ));
        $oneTimeTokens = $this->transactions->run(fn (): int => $this->oneTimeTokens->deleteExpiredAndUsed($now));
        $refreshTokens = $this->transactions->run(fn (): int => $this->refreshTokens->deleteExpiredAndRevoked($now));
        $queuedMails = $this->transactions->run(fn (): int => $this->outbox->deleteQueuedBefore(
            $now->sub(new DateInterval('PT' . self::QUEUED_MAIL_LIFETIME_SECONDS . 'S')),
        ));

        // The counters are not part of any transaction (see RateLimitCounters).
        $windows = $this->rateLimitCounters->deleteWindowsStartedBefore(
            $now->sub(new DateInterval('PT' . self::RATE_LIMIT_RETENTION_SECONDS . 'S')),
        );

        return new PurgeReport($registrations, $oneTimeTokens, $refreshTokens, $windows, $queuedMails);
    }
}
