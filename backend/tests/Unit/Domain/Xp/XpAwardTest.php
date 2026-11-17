<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Xp;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Xp\DuelResult;
use SmokeDetector\Domain\Xp\LeaguePeriod;
use SmokeDetector\Domain\Xp\XpAward;
use SmokeDetector\Domain\Xp\XpSource;

/**
 * One XP event and the vocabulary around it, SRS FR-GAME-01.
 */
final class XpAwardTest extends TestCase
{
    public function test_FR_GAME_01_award_carries_source_amount_and_optional_detail(): void
    {
        $plain = new XpAward(XpSource::CheckIn, 10);
        $detailed = new XpAward(XpSource::StreakMilestone, 50, 7);

        self::assertSame(XpSource::CheckIn, $plain->source);
        self::assertSame(10, $plain->amount);
        self::assertNull($plain->detail);
        self::assertSame(7, $detailed->detail);
    }

    public function test_FR_GAME_01_award_of_zero_xp_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new XpAward(XpSource::Duel, 0);
    }

    public function test_FR_GAME_01_negative_award_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new XpAward(XpSource::Reaction, -2);
    }

    /**
     * The values are stored with every XP event, so they must never change by accident.
     */
    public function test_FR_GAME_01_sources_have_stable_snake_case_values(): void
    {
        self::assertSame(
            ['check_in', 'under_limit', 'smoke_free', 'streak_milestone', 'duel', 'league', 'reaction'],
            array_map(static fn (XpSource $source): string => $source->value, XpSource::cases()),
        );
    }

    public function test_FR_GAME_01_duel_results_have_stable_values(): void
    {
        self::assertSame(
            ['won', 'draw', 'lost', 'forfeited'],
            array_map(static fn (DuelResult $result): string => $result->value, DuelResult::cases()),
        );
    }

    public function test_FR_GAME_01_league_periods_have_stable_values(): void
    {
        self::assertSame(
            ['week', 'month', 'quarter', 'half_year'],
            array_map(static fn (LeaguePeriod $period): string => $period->value, LeaguePeriod::cases()),
        );
    }
}
