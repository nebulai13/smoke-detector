<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Plan\GoalMode;
use SmokeDetector\Application\Plan\Plan;
use SmokeDetector\Application\Plan\PlanRepository;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Domain\Plan\Goal;
use SmokeDetector\Domain\Plan\TaperSpeed;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;
use UnexpectedValueException;

/**
 * The reduction plans in the `plans` table.
 */
final readonly class PdoPlanRepository implements PlanRepository
{
    public function __construct(private Sql $sql)
    {
    }

    public function find(UserId $userId): ?Plan
    {
        $columns = $this->sql->row(
            'SELECT user_id, goal_mode, target, taper_speed, default_product, start_date, rules_version, last_session_before '
            . 'FROM plans WHERE user_id = ?',
            [$userId->value],
        );
        if ($columns === null) {
            return null;
        }
        $row = new Row($columns);

        return new Plan(
            UserId::fromString($row->string('user_id')),
            self::goal(GoalMode::from($row->string('goal_mode')), $row->int('target')),
            TaperSpeed::from($row->string('taper_speed')),
            ProductType::from($row->string('default_product')),
            LocalDate::fromString($row->string('start_date')),
            $row->string('rules_version'),
            self::date($row->nullableString('last_session_before')),
        );
    }

    public function save(Plan $plan): void
    {
        // One statement, so two requests that save at the same moment cannot both insert.
        $this->sql->execute(
            'INSERT INTO plans (user_id, goal_mode, target, taper_speed, default_product, start_date, rules_version, last_session_before) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE goal_mode = VALUES(goal_mode), target = VALUES(target), '
            . 'taper_speed = VALUES(taper_speed), default_product = VALUES(default_product), '
            . 'start_date = VALUES(start_date), rules_version = VALUES(rules_version), '
            . 'last_session_before = VALUES(last_session_before)',
            [
                $plan->userId->value,
                GoalMode::of($plan->goal)->value,
                $plan->goal->target,
                $plan->taperSpeed->value,
                $plan->defaultProduct->value,
                $plan->startDate->value,
                $plan->rulesVersion,
                $plan->lastSessionBefore?->value,
            ],
        );
    }

    private static function date(?string $value): ?LocalDate
    {
        return $value === null ? null : LocalDate::fromString($value);
    }

    /**
     * Mode and target say the same thing twice. A row in which they disagree is damaged.
     */
    private static function goal(GoalMode $mode, int $target): Goal
    {
        if (($mode === GoalMode::Quit) !== ($target === 0)) {
            throw new UnexpectedValueException('A stored plan has a goal mode and a target that contradict each other.');
        }

        return $mode === GoalMode::Quit ? Goal::quit() : Goal::reduceTo($target);
    }
}
