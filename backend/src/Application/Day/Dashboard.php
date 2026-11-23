<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Day;

/**
 * GET /me/dashboard: today against its limit, and the progress with the open days projected.
 */
final readonly class Dashboard
{
    public function __construct(
        public DayView $today,
        public ProgressView $progress,
    ) {
    }
}
