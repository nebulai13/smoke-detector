<?php

declare(strict_types=1);

namespace SmokeDetector\Application;

use RuntimeException;
use Throwable;

/**
 * The work could not be done right now, through nobody's fault, and may well succeed a moment
 * later: the database gave the request up to end a deadlock, or a lock did not come free in time.
 * Everything the work had stored was rolled back.
 *
 * Answered as 503 "service_unavailable" with Retry-After. Nothing retries by itself: a use case
 * also counts rate-limit hits before its transaction, and those are not undone by a rollback.
 * (Mails are queued inside the transaction and are rolled back with it.) It never reaches the
 * caller from counter work after a commit: the work is done then, and the use cases log such a
 * failure instead of reporting it (for example LogIn, DeleteAccount).
 */
final class TemporarilyUnavailable extends RuntimeException
{
    public function __construct(
        /** A sensible time for the client to wait. At least 1. */
        public readonly int $retryAfterSeconds,
        ?Throwable $previous = null,
    ) {
        // Fixed text: what the database said is in the previous exception, for the log.
        parent::__construct('The database could not finish the work right now.', 0, $previous);
    }
}
