<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Housekeeping;

/**
 * How much one cleanup run deleted. Counts only: safe to log.
 */
final readonly class PurgeReport
{
    public function __construct(
        public int $unconfirmedRegistrations,
        public int $oneTimeTokens,
        public int $refreshTokens,
        public int $rateLimitWindows,
        /** Mails of the outbox that were queued longer ago than any link works. */
        public int $queuedMails,
    ) {
    }
}
