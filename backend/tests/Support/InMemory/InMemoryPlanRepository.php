<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Plan\Plan;
use SmokeDetector\Application\Plan\PlanRepository;

/**
 * The reduction plans, in memory. Behaves like PdoPlanRepository (both pass PlanRepositoriesContract).
 */
final class InMemoryPlanRepository implements PlanRepository, InMemoryState
{
    /** @var array<string, Plan> By user id. */
    private array $plans = [];

    public function __construct(?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function find(UserId $userId): ?Plan
    {
        return $this->plans[$userId->value] ?? null;
    }

    public function save(Plan $plan): void
    {
        $this->plans[$plan->userId->value] = $plan;
    }

    /**
     * What account deletion does to the table.
     */
    public function eraseOfUser(UserId $userId): void
    {
        unset($this->plans[$userId->value]);
    }

    public function snapshot(): array
    {
        return $this->plans;
    }

    public function restore(array $snapshot): void
    {
        $plans = [];
        foreach ($snapshot as $userId => $plan) {
            \assert($plan instanceof Plan);
            $plans[(string) $userId] = $plan;
        }
        $this->plans = $plans;
    }
}
