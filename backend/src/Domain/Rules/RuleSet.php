<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Rules;

use InvalidArgumentException;
use SmokeDetector\Domain\Competition\CompetitionRules;
use SmokeDetector\Domain\Level\LevelRules;
use SmokeDetector\Domain\Plan\BaselineRules;
use SmokeDetector\Domain\Plan\PlanRules;
use SmokeDetector\Domain\Streak\StreakRules;
use SmokeDetector\Domain\Xp\XpRules;

/**
 * Every tunable number of the domain under one version name (SRS FR-GAME-01, NFR-04).
 *
 * Results are stored together with the version that produced them, so recomputing with the same
 * version gives the same result even after the numbers were tuned in a later version.
 */
final readonly class RuleSet
{
    public function __construct(
        /** Also the file name of the rule table (config/rules/<version>.php) and a stored column. */
        public string $version,
        public PlanRules $plan,
        public BaselineRules $baseline,
        public StreakRules $streak,
        public XpRules $xp,
        public LevelRules $level,
        public CompetitionRules $competition,
    ) {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,31}\z/', $version) !== 1) {
            throw new InvalidArgumentException(
                'The rule set version must be 1 to 32 characters of a-z, 0-9, ".", "_" or "-" '
                . 'and start with a letter or a digit.',
            );
        }

        // The streak rules decide when a milestone is reached and the XP rules what it pays. If the
        // two lists drift apart, a celebrated milestone silently pays nothing, or XP can never be paid.
        $paid = array_keys($xp->streakMilestones);
        $unpaid = array_diff($streak->milestoneThresholds, $paid);
        if ($unpaid !== []) {
            throw new InvalidArgumentException(
                'Rule set: these streak milestones have no entry in the milestone XP table: '
                . implode(', ', $unpaid) . '.',
            );
        }
        $unreachable = array_diff($paid, $streak->milestoneThresholds);
        if ($unreachable !== []) {
            throw new InvalidArgumentException(
                'Rule set: milestone XP is set for streak lengths that are not streak milestones: '
                . implode(', ', $unreachable) . '.',
            );
        }
    }

    /**
     * Builds a rule set from a rule table such as config/rules/v1.php.
     *
     * Every key is required and no other key is accepted: the table is the reviewable source of
     * every number, so nothing may silently fall back to a constructor default.
     *
     * @param array<array-key, mixed> $table
     */
    public static function fromArray(array $table): self
    {
        $root = RuleSection::root($table, ['version', 'plan', 'baseline', 'streak', 'xp', 'level', 'competition']);

        $plan = $root->section('plan', ['gentleLimitPercent', 'normalLimitPercent', 'fastLimitPercent']);
        $baseline = $root->section('baseline', [
            'weekWeight',
            'monthWeight',
            'weekDays',
            'monthDays',
            'minCheckedInWeekDays',
            'maxIncreasePercent',
            'minIncreaseStepTenths',
        ]);
        $streak = $root->section('streak', ['freezeInterval', 'maxFreezesHeld', 'milestoneThresholds']);
        $xp = $root->section('xp', [
            'checkIn',
            'underLimit',
            'smokeFree',
            'streakMilestones',
            'duelWon',
            'duelDraw',
            'duelLost',
            'duelForfeited',
            'leagueRankXp',
            'leagueMinParticipants',
            'leagueWeekMultiplier',
            'leagueMonthMultiplier',
            'leagueQuarterMultiplier',
            'leagueHalfYearMultiplier',
            'reaction',
            'reactionDailyCap',
        ]);
        $level = $root->section('level', ['factor']);
        $competition = $root->section('competition', ['minScoringBaselineTenths']);

        // Named arguments, so a value can never slip into a neighbouring parameter of the same type.
        return new self(
            version: $root->string('version'),
            plan: new PlanRules(
                gentleLimitPercent: $plan->int('gentleLimitPercent'),
                normalLimitPercent: $plan->int('normalLimitPercent'),
                fastLimitPercent: $plan->int('fastLimitPercent'),
            ),
            baseline: new BaselineRules(
                weekWeight: $baseline->int('weekWeight'),
                monthWeight: $baseline->int('monthWeight'),
                weekDays: $baseline->int('weekDays'),
                monthDays: $baseline->int('monthDays'),
                minCheckedInWeekDays: $baseline->int('minCheckedInWeekDays'),
                maxIncreasePercent: $baseline->int('maxIncreasePercent'),
                minIncreaseStepTenths: $baseline->int('minIncreaseStepTenths'),
            ),
            streak: new StreakRules(
                freezeInterval: $streak->int('freezeInterval'),
                maxFreezesHeld: $streak->int('maxFreezesHeld'),
                milestoneThresholds: $streak->intList('milestoneThresholds'),
            ),
            xp: new XpRules(
                checkIn: $xp->int('checkIn'),
                underLimit: $xp->int('underLimit'),
                smokeFree: $xp->int('smokeFree'),
                streakMilestones: $xp->intMap('streakMilestones'),
                duelWon: $xp->int('duelWon'),
                duelDraw: $xp->int('duelDraw'),
                duelLost: $xp->int('duelLost'),
                duelForfeited: $xp->int('duelForfeited'),
                leagueRankXp: $xp->intMap('leagueRankXp'),
                leagueMinParticipants: $xp->int('leagueMinParticipants'),
                leagueWeekMultiplier: $xp->int('leagueWeekMultiplier'),
                leagueMonthMultiplier: $xp->int('leagueMonthMultiplier'),
                leagueQuarterMultiplier: $xp->int('leagueQuarterMultiplier'),
                leagueHalfYearMultiplier: $xp->int('leagueHalfYearMultiplier'),
                reaction: $xp->int('reaction'),
                reactionDailyCap: $xp->int('reactionDailyCap'),
            ),
            level: new LevelRules(
                factor: $level->int('factor'),
            ),
            competition: new CompetitionRules(
                minScoringBaselineTenths: $competition->int('minScoringBaselineTenths'),
            ),
        );
    }
}
