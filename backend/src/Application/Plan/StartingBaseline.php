<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Plan;

use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Domain\Plan\Baseline;

/**
 * The baseline a user states in the onboarding: a whole number of sessions per day (FR-ACC-06).
 *
 * Only this first value comes from the user. Every later one is calculated by the weekly update.
 */
final readonly class StartingBaseline
{
    public const int MIN = 1;
    public const int MAX = 80;

    /**
     * @throws InvalidValue With a message for the client that does not repeat the input.
     */
    public static function fromInput(int $sessionsPerDay): Baseline
    {
        if ($sessionsPerDay < self::MIN || $sessionsPerDay > self::MAX) {
            throw new InvalidValue(ErrorCode::OutOfRange, 'Must be a whole number from ' . self::MIN . ' to ' . self::MAX . '.');
        }

        return Baseline::fromTenths($sessionsPerDay * 10);
    }
}
