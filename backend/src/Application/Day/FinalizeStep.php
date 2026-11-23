<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

/**
 * What one transaction of FinalizeDays did for a user.
 */
enum FinalizeStep
{
    case DayFinalized;
    case BaselineCalculated;
    /** Nothing is due for the user (or the account is gone): the next user. */
    case NothingDue;
    /** Something is due, but the time of the run is up: the run ends. */
    case OutOfTime;
    /** The step failed and was rolled back: the next user. */
    case Failed;
}
