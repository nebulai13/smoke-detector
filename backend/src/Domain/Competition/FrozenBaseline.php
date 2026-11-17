<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Competition;

use SmokeDetector\Domain\Plan\Baseline;

/**
 * B0: a participant's baseline as it was when the competition window started (SRS FR-PLAN-07).
 *
 * It is a separate type from Baseline so that scoring can only be fed a value that already has the
 * scoring minimum applied, never the live baseline that keeps changing every Monday.
 */
final readonly class FrozenBaseline
{
    /**
     * @param int $tenths Tenths of a session per day, always at least the scoring minimum.
     */
    private function __construct(public int $tenths)
    {
    }

    /**
     * Freezing a value that was frozen before with the same rules returns it unchanged, so a stored
     * B0 can be reloaded through this method.
     */
    public static function freeze(Baseline $baseline, CompetitionRules $rules): self
    {
        return new self(max($baseline->tenths, $rules->minScoringBaselineTenths));
    }

    public function toFloat(): float
    {
        return $this->tenths / 10;
    }
}
