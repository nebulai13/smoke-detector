<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Plan\GoalMode;
use SmokeDetector\Application\Plan\PlanHistory;
use SmokeDetector\Application\Plan\PlanTerms;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;
use UnexpectedValueException;

/**
 * The history of the plans in the `plan_history` table.
 */
final readonly class PdoPlanHistory implements PlanHistory
{
    public function __construct(private Sql $sql)
    {
    }

    public function record(PlanTerms $terms): void
    {
        // One statement, so two requests that record the same day at the same moment cannot both insert.
        $this->sql->execute(
            'INSERT INTO plan_history (user_id, valid_from, goal_mode, target, taper_speed, rules_version) VALUES (?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE goal_mode = VALUES(goal_mode), target = VALUES(target), '
            . 'taper_speed = VALUES(taper_speed), rules_version = VALUES(rules_version)',
            [
                $terms->userId->value,
                $terms->validFrom->value,
                GoalMode::of($terms->goal)->value,
                $terms->goal->target,
                $terms->taperSpeed->value,
                $terms->rulesVersion,
            ],
        );
    }

    public function ofUser(UserId $userId): array
    {
        $entries = [];
        $rows = $this->sql->rows(
            'SELECT user_id, valid_from, goal_mode, target, taper_speed, rules_version FROM plan_history '
            . 'WHERE user_id = ? ORDER BY valid_from',
            [$userId->value],
        );
        foreach ($rows as $columns) {
            $row = new Row($columns);
            $entries[] = new PlanTerms(
                UserId::fromString($row->string('user_id')),
                LocalDate::fromString($row->string('valid_from')),
                self::goal(GoalMode::from($row->string('goal_mode')), $row->int('target')),
                TaperSpeed::from($row->string('taper_speed')),
                $row->string('rules_version'),
            );
        }

        return $entries;
    }

    /**
     * Mode and target say the same thing twice. A row in which they disagree is damaged.
     */
    private static function goal(GoalMode $mode, int $target): Goal
    {
        if (($mode === GoalMode::Quit) !== ($target === 0)) {
            throw new UnexpectedValueException('A stored plan entry has a goal mode and a target that contradict each other.');
        }

        return $mode === GoalMode::Quit ? Goal::quit() : Goal::reduceTo($target);
    }
}
