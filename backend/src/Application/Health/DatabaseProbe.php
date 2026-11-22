<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Health;

/**
 * Finds out whether the database answers.
 */
interface DatabaseProbe
{
    /**
     * Never throws: an unreachable database is an answer, not an error.
     */
    public function isReachable(): bool;
}
