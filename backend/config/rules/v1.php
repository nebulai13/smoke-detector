<?php

declare(strict_types=1);

/**
 * Rule table v1: every tunable number of the game, as decided in docs/SRS.md.
 *
 * This file is the reviewable source of the numbers. It is read through RuleSet::fromArray(), which
 * requires every key and rejects unknown ones, so nothing here falls back to a default in the code.
 *
 * Do not edit a version that is already in use: stored results name the version that produced them
 * and must stay reproducible (NFR-04). Tune numbers in a new file (v2.php) with 'version' => 'v2'.
 */
return [
    'version' => 'v1',

    // Daily limit = floor(baseline × percent / 100), per taper speed (FR-PLAN-01).
    'plan' => [
        'gentleLimitPercent' => 90,
        'normalLimitPercent' => 80,
        'fastLimitPercent' => 70,
    ],

    // Weekly baseline update (FR-PLAN-04 to FR-PLAN-06).
    'baseline' => [
        // New baseline = (7 × week mean + 3 × month mean) / 10, so 0.7 and 0.3.
        'weekWeight' => 7,
        'monthWeight' => 3,
        'weekDays' => 7,
        'monthDays' => 28,
        // Fewer checked-in days in the last week leave the baseline unchanged (FR-PLAN-05).
        'minCheckedInWeekDays' => 4,
        // The baseline rises per week by at most 10 % or 0.5 sessions/day, whichever is larger (FR-PLAN-06).
        'maxIncreasePercent' => 10,
        'minIncreaseStepTenths' => 5,
    ],

    // Streaks and streak freezes (FR-GAME-04, FR-GAME-08).
    'streak' => [
        // One freeze each time the check-in streak reaches a multiple of 7 days.
        'freezeInterval' => 7,
        'maxFreezesHeld' => 2,
        // Check-in streak lengths that are celebrated. Each needs an entry in xp.streakMilestones.
        'milestoneThresholds' => [7, 30, 100, 365],
    ],

    // XP per event (FR-GAME-01, Appendix A). 0 switches a reward off.
    'xp' => [
        'checkIn' => 10,
        'underLimit' => 20,
        // On top of the check-in and under-limit XP.
        'smokeFree' => 40,
        // Check-in streak length => XP, paid the first time only.
        'streakMilestones' => [7 => 50, 30 => 150, 100 => 500, 365 => 1500],
        'duelWon' => 100,
        'duelDraw' => 50,
        // Lost, but completed.
        'duelLost' => 20,
        'duelForfeited' => 0,
        // Final rank => XP for a one-week league. Longer periods multiply it.
        'leagueRankXp' => [1 => 150, 2 => 100, 3 => 50],
        'leagueMinParticipants' => 3,
        'leagueWeekMultiplier' => 1,
        'leagueMonthMultiplier' => 2,
        'leagueQuarterMultiplier' => 3,
        'leagueHalfYearMultiplier' => 4,
        'reaction' => 2,
        'reactionDailyCap' => 10,
    ],

    // Level n requires factor × n × (n + 1) cumulative XP (FR-GAME-05, Appendix A).
    'level' => [
        'factor' => 50,
    ],

    // Competition scoring (FR-PLAN-07).
    'competition' => [
        // The frozen baseline counts as at least 1.0 sessions/day.
        'minScoringBaselineTenths' => 10,
    ],
];
