<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Recovery\MilestoneRecords;
use SmokeDetector\Application\Recovery\ReachedMilestone;
use SmokeDetector\Domain\Recovery\HealthMilestone;

/**
 * The health milestones the users have reached, in memory. Behaves like PdoMilestoneRecords (both
 * pass MilestoneRecordsContract).
 */
final class InMemoryMilestoneRecords implements MilestoneRecords, InMemoryState
{
    /** @var array<string, ReachedMilestone> By user id and milestone key. */
    private array $reached = [];

    public function __construct(?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function add(ReachedMilestone $milestone): bool
    {
        $key = "{$milestone->userId->value} {$milestone->milestone->value}";
        if (isset($this->reached[$key])) {
            // The first time stays.
            return false;
        }
        $this->reached[$key] = $milestone;

        return true;
    }

    public function ofUser(UserId $userId): array
    {
        $reached = [];
        foreach (HealthMilestone::cases() as $case) {
            $milestone = $this->reached["{$userId->value} {$case->value}"] ?? null;
            if ($milestone !== null) {
                $reached[] = $milestone;
            }
        }

        return $reached;
    }

    public function reachedBy(array $userIds): array
    {
        $reached = [];
        foreach ($userIds as $userId) {
            $milestones = array_map(static fn (ReachedMilestone $milestone): HealthMilestone => $milestone->milestone, $this->ofUser($userId));
            if ($milestones !== []) {
                $reached[$userId->value] = $milestones;
            }
        }
        // By user id, as the database answers, whatever the order of the question.
        ksort($reached);

        return $reached;
    }

    /**
     * @return list<ReachedMilestone> Everything stored, in the order it was stored: for the tests.
     */
    public function all(): array
    {
        return array_values($this->reached);
    }

    public function snapshot(): array
    {
        return $this->reached;
    }

    public function restore(array $snapshot): void
    {
        $this->reached = [];
        foreach ($snapshot as $key => $milestone) {
            \assert($milestone instanceof ReachedMilestone);
            $this->reached[(string) $key] = $milestone;
        }
    }
}
