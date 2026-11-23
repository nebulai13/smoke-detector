<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

use RuntimeException;
use SmokeDetector\Application\Logging\Rejection;

/**
 * A day that cannot be checked in (FR-LOG-04): it is before the plan, final, or has not begun for
 * the user. Answered as 409 with a type of its own for each reason.
 */
final class DayNotOpen extends RuntimeException
{
    public function __construct(public readonly Rejection $reason)
    {
        parent::__construct('The day is not open for a check-in: ' . $reason->value . '.');
    }
}
