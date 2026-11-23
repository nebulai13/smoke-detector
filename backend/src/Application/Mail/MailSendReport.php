<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Mail;

/**
 * What one run of the sender did. Counts only: safe to log.
 */
final readonly class MailSendReport
{
    public function __construct(
        /** Handed to the mail server. */
        public int $sent,
        /** Not taken by the mail server this time, and kept for a later try. */
        public int $retried,
        /**
         * Deleted without being sent: too old for its link, its next try would come too late for
         * its link, or its values cannot be opened any more.
         */
        public int $givenUp,
        /**
         * Could not be made (its template cannot be rendered), and kept without a try counted. Not
         * the mail server's fault but the release's: a run with any of these is a failed run.
         */
        public int $notMade = 0,
    ) {
    }

    public function plus(self $other): self
    {
        return new self(
            $this->sent + $other->sent,
            $this->retried + $other->retried,
            $this->givenUp + $other->givenUp,
            $this->notMade + $other->notMade,
        );
    }
}
