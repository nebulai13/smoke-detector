<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Rules;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use SmokeDetector\Domain\Competition\CompetitionRules;
use SmokeDetector\Domain\Level\LevelRules;
use SmokeDetector\Domain\Plan\BaselineRules;
use SmokeDetector\Domain\Plan\PlanRules;
use SmokeDetector\Domain\Rules\RuleSet;
use SmokeDetector\Domain\Streak\StreakRules;
use SmokeDetector\Domain\Xp\XpRules;

/**
 * The versioned rule set, SRS FR-GAME-01 ("server-side, versioned rule table") and NFR-04.
 *
 * The table used here has a different, distinct number for every key on purpose: a value that ends up
 * in the wrong property cannot go unnoticed, which it could with the real table (7 and 7, 10 and 10).
 */
final class RuleSetTest extends TestCase
{
    public function test_FR_GAME_01_rule_set_carries_a_version_and_every_rules_object_of_the_domain(): void
    {
        $plan = new PlanRules();
        $baseline = new BaselineRules();
        $streak = new StreakRules();
        $xp = new XpRules();
        $level = new LevelRules();
        $competition = new CompetitionRules();

        $rules = new RuleSet('v7', $plan, $baseline, $streak, $xp, $level, $competition);

        self::assertSame('v7', $rules->version);
        self::assertSame($plan, $rules->plan);
        self::assertSame($baseline, $rules->baseline);
        self::assertSame($streak, $rules->streak);
        self::assertSame($xp, $rules->xp);
        self::assertSame($level, $rules->level);
        self::assertSame($competition, $rules->competition);
    }

    /**
     * A new *Rules class that is not wired into the rule set would be a tunable number outside the
     * versioned table, which is exactly what the rule set exists to prevent.
     */
    public function test_FR_GAME_01_every_rules_class_of_the_domain_is_part_of_the_rule_set(): void
    {
        $domain = dirname(__DIR__, 4) . '/src/Domain';
        $files = glob($domain . '/*/*Rules.php');
        self::assertNotFalse($files);

        $found = [];
        foreach ($files as $file) {
            $found[] = 'SmokeDetector\\Domain\\' . basename(dirname($file)) . '\\' . basename($file, '.php');
        }

        $carried = [];
        foreach (new ReflectionClass(RuleSet::class)->getProperties() as $property) {
            $type = $property->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $carried[] = $type->getName();
            }
        }

        sort($found);
        sort($carried);
        self::assertSame($found, $carried);
        self::assertContains(PlanRules::class, $carried);
        self::assertCount(6, $carried);
    }

    #[DataProvider('validVersions')]
    public function test_FR_GAME_01_version_is_a_short_name_that_is_safe_as_a_file_name(string $version): void
    {
        self::assertSame($version, self::ruleSetWithVersion($version)->version);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validVersions(): iterable
    {
        yield 'plain' => ['v1'];
        yield 'number only' => ['2'];
        yield 'with separators' => ['v1.2_pilot-b'];
        yield '32 characters' => [str_repeat('a', 32)];
    }

    #[DataProvider('invalidVersions')]
    public function test_FR_GAME_01_unusable_version_names_are_rejected(string $version): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('version');

        self::ruleSetWithVersion($version);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidVersions(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => [' '];
        yield 'upper case' => ['V1'];
        yield 'with a space' => ['v 1'];
        yield 'path' => ['../v1'];
        yield 'starts with a dot' => ['.v1'];
        yield 'trailing newline' => ["v1\n"];
        yield '33 characters' => [str_repeat('a', 33)];
    }

    public function test_FR_GAME_01_streak_milestone_without_an_xp_entry_is_rejected(): void
    {
        // A milestone that is celebrated but missing from the XP table would silently pay nothing.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('30');

        new RuleSet(
            'v1',
            new PlanRules(),
            new BaselineRules(),
            new StreakRules(milestoneThresholds: [7, 30]),
            new XpRules(streakMilestones: [7 => 50]),
            new LevelRules(),
            new CompetitionRules(),
        );
    }

    public function test_FR_GAME_01_xp_entry_for_a_streak_length_that_is_no_milestone_is_rejected(): void
    {
        // The streak rules decide when a milestone is reached, so this XP could never be paid.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('100');

        new RuleSet(
            'v1',
            new PlanRules(),
            new BaselineRules(),
            new StreakRules(milestoneThresholds: [7, 30]),
            new XpRules(streakMilestones: [7 => 50, 30 => 150, 100 => 500]),
            new LevelRules(),
            new CompetitionRules(),
        );
    }

    public function test_FR_GAME_01_milestone_xp_may_be_listed_in_any_order_and_may_be_zero(): void
    {
        $rules = new RuleSet(
            'v1',
            new PlanRules(),
            new BaselineRules(),
            new StreakRules(milestoneThresholds: [7, 30]),
            new XpRules(streakMilestones: [30 => 150, 7 => 0]),
            new LevelRules(),
            new CompetitionRules(),
        );

        self::assertSame([30 => 150, 7 => 0], $rules->xp->streakMilestones);
    }

    public function test_FR_GAME_01_rule_set_without_any_streak_milestone_is_allowed(): void
    {
        $rules = new RuleSet(
            'v1',
            new PlanRules(),
            new BaselineRules(),
            new StreakRules(milestoneThresholds: []),
            new XpRules(streakMilestones: []),
            new LevelRules(),
            new CompetitionRules(),
        );

        self::assertSame([], $rules->streak->milestoneThresholds);
    }

    public function test_FR_GAME_01_rule_set_is_built_from_a_plain_array(): void
    {
        $rules = RuleSet::fromArray(self::table());

        self::assertSame('test-1', $rules->version);
    }

    /**
     * Every key of a section must arrive in the property of the same name.
     */
    #[DataProvider('sections')]
    public function test_FR_GAME_01_every_number_of_the_array_ends_up_in_the_property_of_the_same_name(
        string $section,
    ): void {
        $rules = RuleSet::fromArray(self::table());

        $expected = self::section($section);
        $actual = get_object_vars(match ($section) {
            'plan' => $rules->plan,
            'baseline' => $rules->baseline,
            'streak' => $rules->streak,
            'xp' => $rules->xp,
            'level' => $rules->level,
            'competition' => $rules->competition,
            default => throw new LogicException("Unknown section {$section}."),
        });
        ksort($expected);
        ksort($actual);

        self::assertSame($expected, $actual);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sections(): iterable
    {
        foreach (['plan', 'baseline', 'streak', 'xp', 'level', 'competition'] as $section) {
            yield $section => [$section];
        }
    }

    public function test_NFR_04_same_array_always_builds_an_equal_rule_set(): void
    {
        self::assertEquals(RuleSet::fromArray(self::table()), RuleSet::fromArray(self::table()));
    }

    public function test_FR_GAME_01_order_of_the_keys_does_not_matter(): void
    {
        $table = self::table();
        $reversed = array_reverse($table, true);
        $reversed['xp'] = array_reverse(self::section('xp'), true);

        self::assertEquals(RuleSet::fromArray($table), RuleSet::fromArray($reversed));
    }

    #[DataProvider('unknownKeys')]
    public function test_FR_GAME_01_unknown_keys_are_rejected(?string $section, string $key, string $named): void
    {
        // A typo in a key would otherwise leave the real rule at whatever the code defaults to.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown key "' . $named . '"');

        RuleSet::fromArray(self::tableWith($section, $key, 1));
    }

    /**
     * @return iterable<string, array{?string, string, string}>
     */
    public static function unknownKeys(): iterable
    {
        yield 'top level' => [null, 'badges', 'badges'];
        yield 'plan' => ['plan', 'turboLimitPercent', 'plan.turboLimitPercent'];
        yield 'baseline' => ['baseline', 'weekWeigth', 'baseline.weekWeigth'];
        yield 'streak' => ['streak', 'freezeIntervall', 'streak.freezeIntervall'];
        yield 'xp' => ['xp', 'checkin', 'xp.checkin'];
        yield 'level' => ['level', 'base', 'level.base'];
        yield 'competition' => ['competition', 'minScoringBaseline', 'competition.minScoringBaseline'];
    }

    public function test_FR_GAME_01_array_without_string_keys_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown key "0"');

        RuleSet::fromArray(array_values(self::table()));
    }

    #[DataProvider('everyKey')]
    public function test_FR_GAME_01_every_key_is_required(?string $section, string $key): void
    {
        // The rule table is the reviewable source of every number, so nothing may fall back to a default.
        $table = self::table();
        if ($section === null) {
            unset($table[$key]);
        } else {
            $inner = self::section($section);
            unset($inner[$key]);
            $table[$section] = $inner;
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing key "' . ($section === null ? $key : "{$section}.{$key}") . '"');

        RuleSet::fromArray($table);
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function everyKey(): iterable
    {
        foreach (self::table() as $key => $value) {
            yield $key => [null, $key];
            if (is_array($value)) {
                foreach (array_keys($value) as $inner) {
                    yield "{$key}.{$inner}" => [$key, $inner];
                }
            }
        }
    }

    public function test_FR_GAME_01_every_key_of_the_test_table_is_covered(): void
    {
        // Guards the provider above: 1 version + 6 sections + 3 + 7 + 3 + 16 + 1 + 1 numbers.
        self::assertCount(38, iterator_to_array(self::everyKey()));
    }

    #[DataProvider('wrongTypes')]
    public function test_FR_GAME_01_wrong_types_are_rejected(
        ?string $section,
        string $key,
        mixed $value,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        RuleSet::fromArray(self::tableWith($section, $key, $value));
    }

    /**
     * @return iterable<string, array{?string, string, mixed, string}>
     */
    public static function wrongTypes(): iterable
    {
        yield 'version as a number' => [null, 'version', 1, '"version" must be a string, got int'];
        yield 'version as null' => [null, 'version', null, '"version" must be a string, got null'];
        yield 'section as a number' => [null, 'xp', 10, '"xp" must be an array, got int'];
        yield 'section as a string' => [null, 'level', 'fifty', '"level" must be an array, got string'];
        yield 'number as a string' => ['xp', 'checkIn', '10', '"xp.checkIn" must be an integer, got string'];
        yield 'number as a float' => ['plan', 'fastLimitPercent', 70.0, '"plan.fastLimitPercent" must be an integer, got float'];
        yield 'number as a bool' => ['streak', 'maxFreezesHeld', true, '"streak.maxFreezesHeld" must be an integer, got bool'];
        yield 'number as null' => ['level', 'factor', null, '"level.factor" must be an integer, got null'];
        yield 'number as an array' => ['competition', 'minScoringBaselineTenths', [10], '"competition.minScoringBaselineTenths" must be an integer, got array'];
        yield 'thresholds as a number' => ['streak', 'milestoneThresholds', 7, '"streak.milestoneThresholds" must be a list of integers, got int'];
        yield 'thresholds with keys' => ['streak', 'milestoneThresholds', [5 => 55, 50 => 555], '"streak.milestoneThresholds" must be a list of integers'];
        yield 'thresholds with a string' => ['streak', 'milestoneThresholds', [5, '50'], '"streak.milestoneThresholds" must be a list of integers'];
        yield 'thresholds with a float' => ['streak', 'milestoneThresholds', [5, 50.0], '"streak.milestoneThresholds" must be a list of integers'];
        yield 'table as a number' => ['xp', 'streakMilestones', 50, '"xp.streakMilestones" must be a table of integer => integer, got int'];
        yield 'table with a named key' => ['xp', 'streakMilestones', ['five' => 55, 50 => 555], '"xp.streakMilestones" must be a table of integer => integer'];
        yield 'table with a string value' => ['xp', 'leagueRankXp', [1 => '151', 2 => 102], '"xp.leagueRankXp" must be a table of integer => integer'];
        yield 'table with a nested table' => ['xp', 'leagueRankXp', [1 => [151], 2 => 102], '"xp.leagueRankXp" must be a table of integer => integer'];
    }

    #[DataProvider('nonsensicalValues')]
    public function test_FR_GAME_01_nonsensical_values_are_rejected(
        string $section,
        string $key,
        mixed $value,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        RuleSet::fromArray(self::tableWith($section, $key, $value));
    }

    /**
     * @return iterable<string, array{string, string, mixed, string}>
     */
    public static function nonsensicalValues(): iterable
    {
        yield 'negative check-in XP' => ['xp', 'checkIn', -10, 'checkIn'];
        yield 'negative duel XP' => ['xp', 'duelLost', -20, 'duelLost'];
        yield 'negative milestone XP' => ['xp', 'streakMilestones', [5 => -55, 50 => 555], 'streakMilestones'];
        yield 'negative league XP' => ['xp', 'leagueRankXp', [1 => 151, 2 => -102], 'leagueRankXp'];
        yield 'league rank zero' => ['xp', 'leagueRankXp', [0 => 151, 1 => 102], 'leagueRankXp'];
        yield 'negative reaction cap' => ['xp', 'reactionDailyCap', -1, 'reactionDailyCap'];
        yield 'taper percentage of zero' => ['plan', 'fastLimitPercent', 0, 'fast taper speed must be between 1 and 100'];
        yield 'taper percentage above 100' => ['plan', 'gentleLimitPercent', 101, 'gentle taper speed must be between 1 and 100'];
        yield 'fast taper gentler than normal' => ['plan', 'fastLimitPercent', 90, 'faster taper speed'];
        yield 'unsorted milestone thresholds' => ['streak', 'milestoneThresholds', [50, 5], 'strictly ascending'];
        yield 'duplicate milestone thresholds' => ['streak', 'milestoneThresholds', [5, 5, 50], 'strictly ascending'];
        yield 'milestone threshold of zero' => ['streak', 'milestoneThresholds', [0, 5, 50], 'strictly ascending'];
        yield 'milestone threshold without XP' => ['streak', 'milestoneThresholds', [5, 50, 500], '500'];
        yield 'milestone XP without threshold' => ['xp', 'streakMilestones', [5 => 55, 50 => 555, 500 => 5555], '500'];
        yield 'freeze interval of zero' => ['streak', 'freezeInterval', 0, 'freeze interval'];
        yield 'negative freeze cap' => ['streak', 'maxFreezesHeld', -1, 'freezes held'];
        yield 'month window shorter than week window' => ['baseline', 'monthDays', 4, 'month window'];
        yield 'week window of zero days' => ['baseline', 'weekDays', 0, 'week window'];
        yield 'negative baseline weight' => ['baseline', 'weekWeight', -6, 'weights'];
        yield 'minimum check-ins above the week' => ['baseline', 'minCheckedInWeekDays', 6, 'checked-in days'];
        yield 'negative increase cap' => ['baseline', 'maxIncreasePercent', -12, 'increase cap'];
        yield 'level factor of zero' => ['level', 'factor', 0, 'level factor'];
        yield 'scoring minimum of zero' => ['competition', 'minScoringBaselineTenths', 0, 'scoring minimum'];
    }

    /**
     * Numbers the maths of the domain cannot handle exactly must be refused when the table is read,
     * not when a user's day is finalized with them.
     */
    #[DataProvider('numbersTooLargeForExactMaths')]
    public function test_FR_GAME_01_numbers_too_large_for_exact_maths_are_rejected(
        string $section,
        string $key,
        mixed $value,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        RuleSet::fromArray(self::tableWith($section, $key, $value));
    }

    /**
     * @return iterable<string, array{string, string, mixed, string}>
     */
    public static function numbersTooLargeForExactMaths(): iterable
    {
        $huge = ['the integer limit' => PHP_INT_MAX, '2^62' => 2 ** 62];
        $numbers = [
            'baseline' => ['weekWeight', 'monthWeight', 'monthDays', 'maxIncreasePercent', 'minIncreaseStepTenths'],
            'xp' => [
                'checkIn', 'underLimit', 'smokeFree', 'duelWon', 'duelDraw', 'duelLost', 'duelForfeited',
                'leagueMinParticipants', 'leagueWeekMultiplier', 'leagueMonthMultiplier',
                'leagueQuarterMultiplier', 'leagueHalfYearMultiplier', 'reaction', 'reactionDailyCap',
            ],
        ];
        foreach ($huge as $name => $value) {
            foreach ($numbers as $section => $keys) {
                foreach ($keys as $key) {
                    yield "{$section}.{$key} at {$name}" => [$section, $key, $value, 'above'];
                }
            }
            yield "competition.minScoringBaselineTenths at {$name}" => [
                'competition', 'minScoringBaselineTenths', $value, 'above',
            ];
            yield "xp.streakMilestones at {$name}" => ['xp', 'streakMilestones', [5 => 55, 50 => $value], 'more than'];
            yield "xp.leagueRankXp at {$name}" => ['xp', 'leagueRankXp', [1 => $value, 2 => 102], 'more than'];
        }
    }

    public function test_FR_GAME_01_wrong_type_is_reported_as_a_full_sentence_naming_key_and_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rule set: "xp.checkIn" must be an integer, got string.');

        RuleSet::fromArray(self::tableWith('xp', 'checkIn', '10'));
    }

    public function test_FR_GAME_01_unusable_version_is_reported_with_the_allowed_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The rule set version must be 1 to 32 characters of a-z, 0-9, ".", "_" or "-" '
            . 'and start with a letter or a digit.',
        );

        self::ruleSetWithVersion('V1');
    }

    public function test_FR_GAME_01_streak_milestones_without_an_xp_entry_are_all_named(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Rule set: these streak milestones have no entry in the milestone XP table: 30, 100.',
        );

        new RuleSet(
            'v1',
            new PlanRules(),
            new BaselineRules(),
            new StreakRules(milestoneThresholds: [7, 30, 100]),
            new XpRules(streakMilestones: [7 => 50]),
            new LevelRules(),
            new CompetitionRules(),
        );
    }

    public function test_FR_GAME_01_xp_entries_for_streak_lengths_that_are_no_milestones_are_all_named(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Rule set: milestone XP is set for streak lengths that are not streak milestones: 30, 100.',
        );

        new RuleSet(
            'v1',
            new PlanRules(),
            new BaselineRules(),
            new StreakRules(milestoneThresholds: [7]),
            new XpRules(streakMilestones: [7 => 50, 30 => 150, 100 => 500]),
            new LevelRules(),
            new CompetitionRules(),
        );
    }

    public function test_FR_GAME_01_unusable_version_in_the_array_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('version');

        RuleSet::fromArray(self::tableWith(null, 'version', 'Version One'));
    }

    private static function ruleSetWithVersion(string $version): RuleSet
    {
        return new RuleSet(
            $version,
            new PlanRules(),
            new BaselineRules(),
            new StreakRules(),
            new XpRules(),
            new LevelRules(),
            new CompetitionRules(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function tableWith(?string $section, string $key, mixed $value): array
    {
        $table = self::table();
        if ($section === null) {
            $table[$key] = $value;
        } else {
            $table[$section] = [...self::section($section), $key => $value];
        }

        return $table;
    }

    /**
     * @return array<string, mixed>
     */
    private static function section(string $name): array
    {
        $section = self::table()[$name] ?? null;
        if (!is_array($section)) {
            throw new LogicException("The test table has no section {$name}.");
        }

        $values = [];
        foreach ($section as $key => $value) {
            $values[(string) $key] = $value;
        }

        return $values;
    }

    /**
     * A complete, valid rule table in which no two numbers of a section are the same.
     *
     * @return array<string, mixed>
     */
    private static function table(): array
    {
        return [
            'version' => 'test-1',
            'plan' => [
                'gentleLimitPercent' => 95,
                'normalLimitPercent' => 85,
                'fastLimitPercent' => 75,
            ],
            'baseline' => [
                'weekWeight' => 6,
                'monthWeight' => 4,
                'weekDays' => 5,
                'monthDays' => 20,
                'minCheckedInWeekDays' => 3,
                'maxIncreasePercent' => 12,
                'minIncreaseStepTenths' => 8,
            ],
            'streak' => [
                'freezeInterval' => 9,
                'maxFreezesHeld' => 3,
                'milestoneThresholds' => [5, 50],
            ],
            'xp' => [
                'checkIn' => 11,
                'underLimit' => 21,
                'smokeFree' => 41,
                'streakMilestones' => [5 => 55, 50 => 555],
                'duelWon' => 101,
                'duelDraw' => 51,
                'duelLost' => 22,
                'duelForfeited' => 1,
                'leagueRankXp' => [1 => 151, 2 => 102],
                'leagueMinParticipants' => 4,
                'leagueWeekMultiplier' => 2,
                'leagueMonthMultiplier' => 3,
                'leagueQuarterMultiplier' => 5,
                'leagueHalfYearMultiplier' => 7,
                'reaction' => 6,
                'reactionDailyCap' => 13,
            ],
            'level' => [
                'factor' => 60,
            ],
            'competition' => [
                'minScoringBaselineTenths' => 15,
            ],
        ];
    }
}
