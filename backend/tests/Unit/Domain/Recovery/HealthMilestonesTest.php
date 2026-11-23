<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Recovery;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Recovery\HealthMilestone;
use SmokeDetector\Domain\Recovery\HealthMilestoneState;
use SmokeDetector\Domain\Recovery\HealthMilestones;

/**
 * Health milestones by the time since the last session (SRS FR-GAME-09, Appendix C, HLD §4.10).
 *
 * A milestone is a fixed span of time. A month counts as 30 days and a year as 365 days: the sources
 * give round figures, and a fixed span needs no calendar and no time zone.
 */
final class HealthMilestonesTest extends TestCase
{
    public function test_FR_GAME_09_the_milestones_of_appendix_C_in_their_order(): void
    {
        // The keys are sent to the clients, which have the text, the source and the disclaimer for each.
        self::assertSame(
            [
                'heart_rate' => 20,
                'carbon_monoxide' => 12 * 60,
                'circulation' => 14 * 24 * 60,
                'breathing' => 30 * 24 * 60,
                'heart_disease_halved' => 365 * 24 * 60,
                'stroke' => 5 * 365 * 24 * 60,
                'lung_cancer_halved' => 10 * 365 * 24 * 60,
                'heart_disease_gone' => 15 * 365 * 24 * 60,
            ],
            array_combine(
                array_map(static fn (HealthMilestone $m): string => $m->value, HealthMilestone::cases()),
                array_map(static fn (HealthMilestone $m): int => $m->afterMinutes(), HealthMilestone::cases()),
            ),
        );
    }

    public function test_FR_GAME_09_every_milestone_is_listed_with_the_moment_it_is_reached(): void
    {
        $since = self::utc('2026-11-20 08:00:00');

        $states = HealthMilestones::at($since, self::utc('2026-11-20 08:00:00'));

        self::assertSame(HealthMilestone::cases(), array_map(
            static fn (HealthMilestoneState $state): HealthMilestone => $state->milestone,
            $states,
        ));
        self::assertSame(
            [
                '2026-11-20 08:20:00',
                '2026-11-20 20:00:00',
                '2026-12-04 08:00:00',
                '2026-12-20 08:00:00',
                '2027-11-20 08:00:00',
                // Five years of 365 days: the leap day of 2028 moves the date back by one.
                '2031-11-19 08:00:00',
                '2036-11-17 08:00:00',
                '2041-11-16 08:00:00',
            ],
            array_map(
                static fn (HealthMilestoneState $state): string => $state->reachedAt->format('Y-m-d H:i:s'),
                $states,
            ),
        );
    }

    /**
     * @param list<string> $reached
     */
    #[DataProvider('moments')]
    public function test_FR_GAME_09_a_milestone_is_reached_from_its_moment_on(string $now, array $reached): void
    {
        $states = HealthMilestones::at(self::utc('2026-11-20 08:00:00'), self::utc($now));

        self::assertSame($reached, self::reachedKeys($states));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function moments(): iterable
    {
        yield 'at the session' => ['2026-11-20 08:00:00', []];
        yield 'a second before 20 minutes' => ['2026-11-20 08:19:59', []];
        yield 'at 20 minutes' => ['2026-11-20 08:20:00', ['heart_rate']];
        yield 'a second before 12 hours' => ['2026-11-20 19:59:59', ['heart_rate']];
        yield 'at 12 hours' => ['2026-11-20 20:00:00', ['heart_rate', 'carbon_monoxide']];
        yield 'at 2 weeks' => ['2026-12-04 08:00:00', ['heart_rate', 'carbon_monoxide', 'circulation']];
        yield 'at 30 days' => [
            '2026-12-20 08:00:00',
            ['heart_rate', 'carbon_monoxide', 'circulation', 'breathing'],
        ];
        yield 'a second before a year' => [
            '2027-11-20 07:59:59',
            ['heart_rate', 'carbon_monoxide', 'circulation', 'breathing'],
        ];
        yield 'after 20 years' => [
            '2046-11-20 08:00:00',
            [
                'heart_rate',
                'carbon_monoxide',
                'circulation',
                'breathing',
                'heart_disease_halved',
                'stroke',
                'lung_cancer_halved',
                'heart_disease_gone',
            ],
        ];
    }

    public function test_FR_GAME_09_a_session_later_than_now_has_reached_nothing(): void
    {
        // A device clock that runs ahead must not cause an error.
        $states = HealthMilestones::at(self::utc('2026-11-20 08:00:00'), self::utc('2026-11-20 07:00:00'));

        self::assertCount(8, $states);
        self::assertSame([], self::reachedKeys($states));
    }

    public function test_FR_GAME_09_the_span_is_the_same_in_every_time_zone(): void
    {
        // Berlin leaves summer time on 25 October 2026 at 03:00, so that night has 13 hours on the wall
        // clock from 20:00 to 08:00. 12 hours are 12 hours all the same: 20:00 summer time is 18:00 UTC,
        // and the milestone is reached at 06:00 UTC (07:00 on the wall clock), not at 07:00 UTC.
        $since = new DateTimeImmutable('2026-10-24 20:00:00', new DateTimeZone('Europe/Berlin'));

        $states = HealthMilestones::at($since, self::utc('2026-10-25 05:59:59'));
        self::assertSame(['heart_rate'], self::reachedKeys($states));

        $states = HealthMilestones::at($since, self::utc('2026-10-25 06:00:00'));
        self::assertSame(['heart_rate', 'carbon_monoxide'], self::reachedKeys($states));
        self::assertSame(
            '2026-10-25 06:00:00',
            $states[1]->reachedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );
    }

    /**
     * @param list<HealthMilestoneState> $states
     *
     * @return list<string>
     */
    private static function reachedKeys(array $states): array
    {
        return array_values(array_map(
            static fn (HealthMilestoneState $state): string => $state->milestone->value,
            array_filter($states, static fn (HealthMilestoneState $state): bool => $state->reached),
        ));
    }

    private static function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }
}
