<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Recovery;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Recovery\HealthMilestone;
use SmokeDetector\Domain\Recovery\MilestoneSharing;

/**
 * What a user lets friends see of the health milestones (SRS FR-GAME-09, FR-FRND-03, HLD §4.11):
 * the user's own setting, decided by the owner on 2026-11-23.
 */
final class MilestoneSharingTest extends TestCase
{
    public function test_FR_GAME_09_the_three_settings_and_their_stable_values(): void
    {
        // The values are stored and sent to the clients.
        self::assertSame(
            ['from_two_weeks', 'private', 'all'],
            array_map(static fn (MilestoneSharing $sharing): string => $sharing->value, MilestoneSharing::cases()),
        );
    }

    public function test_FR_GAME_09_without_a_choice_friends_see_the_milestones_from_two_weeks_on(): void
    {
        self::assertSame(MilestoneSharing::FromTwoWeeks, MilestoneSharing::default());
    }

    /**
     * @param list<string> $shown
     */
    #[DataProvider('settings')]
    public function test_FR_FRND_03_a_setting_says_which_milestones_friends_see(MilestoneSharing $sharing, array $shown): void
    {
        self::assertSame(
            $shown,
            array_values(array_map(
                static fn (HealthMilestone $milestone): string => $milestone->value,
                array_filter(HealthMilestone::cases(), $sharing->shows(...)),
            )),
        );
    }

    /**
     * @return iterable<string, array{MilestoneSharing, list<string>}>
     */
    public static function settings(): iterable
    {
        // The two short ones say that a night passed without a session, which is nobody's business
        // unless the user says so.
        yield 'from two weeks' => [
            MilestoneSharing::FromTwoWeeks,
            ['circulation', 'breathing', 'heart_disease_halved', 'stroke', 'lung_cancer_halved', 'heart_disease_gone'],
        ];
        yield 'private' => [MilestoneSharing::Private, []];
        yield 'all' => [
            MilestoneSharing::All,
            ['heart_rate', 'carbon_monoxide', 'circulation', 'breathing', 'heart_disease_halved', 'stroke', 'lung_cancer_halved', 'heart_disease_gone'],
        ];
    }

    public function test_FR_GAME_09_two_weeks_is_the_span_of_the_circulation_milestone(): void
    {
        // The setting is named after a span. If the milestones change, the name must still be true.
        self::assertSame(14 * 24 * 60, HealthMilestone::Circulation->afterMinutes());
        self::assertFalse(MilestoneSharing::FromTwoWeeks->shows(HealthMilestone::CarbonMonoxide));
        self::assertTrue(MilestoneSharing::FromTwoWeeks->shows(HealthMilestone::Circulation));
    }
}
