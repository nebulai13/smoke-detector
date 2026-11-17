<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Xp;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Domain\Xp\XpRules;

/**
 * The XP rule table, SRS FR-GAME-01. The defaults are the values of SRS Appendix A.
 */
final class XpRulesTest extends TestCase
{
    public function test_FR_GAME_01_default_day_values_match_appendix_a(): void
    {
        $rules = new XpRules();

        self::assertSame(10, $rules->checkIn);
        self::assertSame(20, $rules->underLimit);
        self::assertSame(40, $rules->smokeFree);
    }

    public function test_FR_GAME_01_default_streak_milestones_match_appendix_a(): void
    {
        self::assertSame([7 => 50, 30 => 150, 100 => 500, 365 => 1500], (new XpRules())->streakMilestones);
    }

    public function test_FR_GAME_01_default_duel_values_match_appendix_a(): void
    {
        $rules = new XpRules();

        self::assertSame(100, $rules->duelWon);
        self::assertSame(50, $rules->duelDraw);
        self::assertSame(20, $rules->duelLost);
        self::assertSame(0, $rules->duelForfeited);
    }

    public function test_FR_GAME_01_default_league_values_match_appendix_a(): void
    {
        $rules = new XpRules();

        self::assertSame([1 => 150, 2 => 100, 3 => 50], $rules->leagueRankXp);
        self::assertSame(3, $rules->leagueMinParticipants);
        self::assertSame(1, $rules->leagueWeekMultiplier);
        self::assertSame(2, $rules->leagueMonthMultiplier);
        self::assertSame(3, $rules->leagueQuarterMultiplier);
        self::assertSame(4, $rules->leagueHalfYearMultiplier);
    }

    public function test_FR_GAME_01_default_reaction_values_match_appendix_a(): void
    {
        $rules = new XpRules();

        self::assertSame(2, $rules->reaction);
        self::assertSame(10, $rules->reactionDailyCap);
    }

    /**
     * XP is a reward only. A negative number in the table would punish users, which the product never does.
     *
     * @param array<string, int> $override
     */
    #[DataProvider('negativeScalarRules')]
    public function test_FR_GAME_01_negative_numbers_in_the_rule_table_are_rejected(array $override): void
    {
        $this->expectException(InvalidArgumentException::class);

        new XpRules(...$override);
    }

    /**
     * @return iterable<string, array{array<string, int>}>
     */
    public static function negativeScalarRules(): iterable
    {
        $names = [
            'checkIn', 'underLimit', 'smokeFree',
            'duelWon', 'duelDraw', 'duelLost', 'duelForfeited',
            'leagueMinParticipants',
            'leagueWeekMultiplier', 'leagueMonthMultiplier', 'leagueQuarterMultiplier', 'leagueHalfYearMultiplier',
            'reaction', 'reactionDailyCap',
        ];
        foreach ($names as $name) {
            yield $name => [[$name => -1]];
        }
    }

    /**
     * @param array<string, int> $override
     */
    #[DataProvider('largestScalarRules')]
    public function test_FR_GAME_01_every_number_may_be_as_large_as_the_documented_maximum(array $override): void
    {
        $rules = new XpRules(...$override);

        foreach ($override as $name => $value) {
            self::assertSame(1_000_000, $value);
            self::assertSame($value, $rules->{$name});
        }
    }

    /**
     * @return iterable<string, array{array<string, int>}>
     */
    public static function largestScalarRules(): iterable
    {
        foreach (self::scalarNames() as $name) {
            yield $name => [[$name => XpRules::MAX_VALUE]];
        }
    }

    /**
     * Above the maximum, sums and products of XP could leave the integer range and stop being exact.
     */
    #[DataProvider('tooLargeScalarRules')]
    public function test_FR_GAME_01_numbers_above_the_documented_maximum_are_rejected(string $name, int $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("XP rule {$name} cannot be above 1000000.");

        new XpRules(...[$name => $value]);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function tooLargeScalarRules(): iterable
    {
        foreach (self::scalarNames() as $name) {
            yield "{$name} one above" => [$name, XpRules::MAX_VALUE + 1];
            yield "{$name} at the integer limit" => [$name, PHP_INT_MAX];
        }
    }

    public function test_FR_GAME_01_table_values_may_be_as_large_as_the_documented_maximum(): void
    {
        $rules = new XpRules(
            streakMilestones: [7 => XpRules::MAX_VALUE],
            leagueRankXp: [1 => XpRules::MAX_VALUE],
        );

        self::assertSame([7 => 1_000_000], $rules->streakMilestones);
        self::assertSame([1 => 1_000_000], $rules->leagueRankXp);
    }

    public function test_FR_GAME_01_streak_milestone_xp_above_the_documented_maximum_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('XP rule streakMilestones cannot hold more than 1000000 XP.');

        new XpRules(streakMilestones: [7 => 50, 30 => XpRules::MAX_VALUE + 1]);
    }

    public function test_FR_GAME_01_league_rank_xp_above_the_documented_maximum_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('XP rule leagueRankXp cannot hold more than 1000000 XP.');

        new XpRules(leagueRankXp: [1 => PHP_INT_MAX]);
    }

    /**
     * @return list<string>
     */
    private static function scalarNames(): array
    {
        return [
            'checkIn', 'underLimit', 'smokeFree',
            'duelWon', 'duelDraw', 'duelLost', 'duelForfeited',
            'leagueMinParticipants',
            'leagueWeekMultiplier', 'leagueMonthMultiplier', 'leagueQuarterMultiplier', 'leagueHalfYearMultiplier',
            'reaction', 'reactionDailyCap',
        ];
    }

    public function test_FR_GAME_01_negative_streak_milestone_xp_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new XpRules(streakMilestones: [7 => -50]);
    }

    public function test_FR_GAME_01_streak_milestone_threshold_below_1_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new XpRules(streakMilestones: [0 => 50]);
    }

    public function test_FR_GAME_01_negative_league_rank_xp_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new XpRules(leagueRankXp: [1 => -150]);
    }

    public function test_FR_GAME_01_league_rank_below_1_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new XpRules(leagueRankXp: [0 => 150]);
    }

    public function test_FR_GAME_01_zero_values_and_empty_tables_are_allowed(): void
    {
        $rules = new XpRules(checkIn: 0, streakMilestones: [], leagueRankXp: [], reactionDailyCap: 0);

        self::assertSame(0, $rules->checkIn);
        self::assertSame([], $rules->streakMilestones);
        self::assertSame([], $rules->leagueRankXp);
        self::assertSame(0, $rules->reactionDailyCap);
    }
}
