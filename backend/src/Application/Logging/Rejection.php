<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Logging;

/**
 * Why an item of a sync was not stored (HLD §4.6 "POST /logs/sync"), and why a day cannot be
 * checked in. The values are the reasons of the contract (schema LogItemResult): a value that was
 * released is never renamed or removed.
 */
enum Rejection: string
{
    /** The day of the log, or the day it would move to, is final (FR-LOG-07). */
    case DayFinalized = 'day_finalized';
    /** More than five minutes ahead of the server's clock, or a day that has not begun. */
    case InFuture = 'in_future';
    /** Before the first day of the plan. */
    case BeforeStart = 'before_start';
    /** The day would hold more sessions than SyncLogs::MAX_SESSIONS_PER_DAY (SRS OQ-9). */
    case DayFull = 'day_full';
    /** The id belongs to a log of another account. */
    case NotYours = 'not_yours';
}
