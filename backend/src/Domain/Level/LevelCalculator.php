<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Level;

use InvalidArgumentException;

/**
 * Levels from cumulative XP (SRS FR-GAME-05, Appendix A).
 *
 * Level n requires factor × n × (n + 1) XP in total. New users are on level 0. All maths is done
 * in integers, so a level is exact for every XP total from 0 to PHP_INT_MAX.
 */
final readonly class LevelCalculator
{
    /**
     * Most levels that one step can cross. A list of that many levels takes about 16 MB, and the
     * largest single award a rule table can pay (XpRules::MAX_VALUE XP times a multiplier of
     * XpRules::MAX_VALUE, at a level factor of 1) stays just below it.
     */
    public const int MAX_LEVELS_GAINED = 1_000_000;

    public function __construct(private LevelRules $rules)
    {
    }

    public function levelFor(int $totalXp): int
    {
        if ($totalXp < 0) {
            throw new InvalidArgumentException('A total XP cannot be negative.');
        }

        // factor × n × (n + 1) ≤ XP is the same as n × (n + 1) ≤ floor(XP / factor), because n × (n + 1) is whole.
        $units = intdiv($totalXp, $this->rules->factor);

        // n² ≤ n × (n + 1) < (n + 1)², so the level is the rounded-down square root of the units or one below.
        // The float root is only a first guess: above 2^53 a float cannot hold every integer. The two loops
        // move the guess to the exact level whatever it was, so the result never depends on float rounding.
        // They compare by division, because the products themselves could overflow.
        $level = (int) sqrt((float) $units);
        while ($level > 0 && $level + 1 > intdiv($units, $level)) {
            // level × (level + 1) > units: the guess is too high.
            $level--;
        }
        while ($level + 2 <= intdiv($units, $level + 1)) {
            // (level + 1) × (level + 2) ≤ units: the next level is already reached.
            $level++;
        }

        return $level;
    }

    /**
     * Cumulative XP at which the given level is reached.
     */
    public function xpRequiredFor(int $level): int
    {
        if ($level < 0) {
            throw new InvalidArgumentException('A level cannot be negative.');
        }
        if ($level > $this->maxLevel()) {
            throw new InvalidArgumentException("Level {$level} is above the highest level an XP total can reach.");
        }

        return $this->rules->factor * $level * ($level + 1);
    }

    /**
     * The highest level whose threshold still fits into an integer, so the level of PHP_INT_MAX XP.
     */
    public function maxLevel(): int
    {
        return $this->levelFor(PHP_INT_MAX);
    }

    public function progressFor(int $totalXp): LevelProgress
    {
        $level = $this->levelFor($totalXp);

        return new LevelProgress(
            $level,
            $totalXp - $this->xpRequiredFor($level),
            // Threshold of the next level minus the current one. Taken directly, because at the
            // highest level the next threshold itself no longer fits into an integer.
            2 * $this->rules->factor * ($level + 1),
        );
    }

    /**
     * The levels reached by going from $xpBefore to $xpAfter, lowest first. One award can cross several.
     *
     * @return list<int>
     */
    public function levelsGained(int $xpBefore, int $xpAfter): array
    {
        if ($xpAfter < $xpBefore) {
            throw new InvalidArgumentException('XP is only ever added, so the XP after cannot be below the XP before.');
        }

        $before = $this->levelFor($xpBefore);
        $reached = $this->levelFor($xpAfter);
        // One list entry per level. Without a bound an absurd XP jump would run out of memory, and
        // that is a fatal error no caller can catch.
        if ($reached - $before > self::MAX_LEVELS_GAINED) {
            throw new InvalidArgumentException(
                'More than ' . self::MAX_LEVELS_GAINED . ' levels cannot be gained in one step.',
            );
        }

        $levels = [];
        for ($level = $before + 1; $level <= $reached; $level++) {
            $levels[] = $level;
        }

        return $levels;
    }
}
