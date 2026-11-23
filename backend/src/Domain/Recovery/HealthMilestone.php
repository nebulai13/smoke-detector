<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Recovery;

/**
 * What the body recovers after the last session, and when (SRS FR-GAME-09, Appendix C).
 *
 * Each milestone is a fixed span: a month counts as 30 days and a year as 365 days, because the
 * sources give round figures and a fixed span needs no calendar. The values are sent to the clients,
 * which hold the texts, the sources and the medical disclaimer, so they must stay stable.
 */
enum HealthMilestone: string
{
    case HeartRate = 'heart_rate';
    case CarbonMonoxide = 'carbon_monoxide';
    case Circulation = 'circulation';
    case Breathing = 'breathing';
    case HeartDiseaseHalved = 'heart_disease_halved';
    case Stroke = 'stroke';
    case LungCancerHalved = 'lung_cancer_halved';
    case HeartDiseaseGone = 'heart_disease_gone';

    private const int HOUR = 60;
    private const int DAY = 24 * self::HOUR;
    private const int YEAR = 365 * self::DAY;

    public function afterMinutes(): int
    {
        return match ($this) {
            self::HeartRate => 20,
            self::CarbonMonoxide => 12 * self::HOUR,
            self::Circulation => 14 * self::DAY,
            self::Breathing => 30 * self::DAY,
            self::HeartDiseaseHalved => self::YEAR,
            self::Stroke => 5 * self::YEAR,
            self::LungCancerHalved => 10 * self::YEAR,
            self::HeartDiseaseGone => 15 * self::YEAR,
        };
    }
}
