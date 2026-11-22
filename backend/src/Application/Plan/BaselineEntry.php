<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Plan;

use InvalidArgumentException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Plan\Baseline;
use SmokeDetector\Domain\Plan\BaselineSource;

/**
 * One entry of a user's baseline history (HLD §4.3 "baseline_history", FR-PLAN-02): the value, the
 * local day from which it counts, and what it was calculated from.
 */
final readonly class BaselineEntry
{
    /** 999.9 sessions per day: what the DECIMAL(4,1) column holds. */
    public const int MAX_TENTHS = 9999;

    public function __construct(
        public UserId $userId,
        public LocalDate $validFrom,
        public Baseline $value,
        public BaselineSource $source,
        /** Inputs of the weekly update (FR-PLAN-04). All four are null for the onboarding value. */
        public ?float $weekMean,
        public ?float $monthMean,
        public ?int $weekDays,
        public ?int $monthDays,
        /** The version of the rule table the value was calculated with (NFR-04). */
        public string $rulesVersion,
    ) {
        // The database would refuse it as well (strict mode). Said here, it names the reason.
        if ($value->tenths > self::MAX_TENTHS) {
            throw new InvalidArgumentException('A baseline above 999.9 sessions per day cannot be stored.');
        }
    }

    /**
     * The first entry: what the user stated in the onboarding.
     */
    public static function onboarding(UserId $userId, LocalDate $validFrom, Baseline $value, string $rulesVersion): self
    {
        return new self($userId, $validFrom, $value, BaselineSource::Onboarding, null, null, null, null, $rulesVersion);
    }
}
