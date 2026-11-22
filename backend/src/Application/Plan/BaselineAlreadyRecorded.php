<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Plan;

use RuntimeException;

/**
 * The baseline history already has an entry of that user for that day. An entry is never replaced
 * (FR-PLAN-02), so a job that runs twice finds its own first result here and can stop.
 */
final class BaselineAlreadyRecorded extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The baseline history already has an entry for that user and day.');
    }
}
