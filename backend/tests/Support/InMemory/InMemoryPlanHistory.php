<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Plan\PlanHistory;
use SmokeDetector\Application\Plan\PlanTerms;

/**
 * The history of the plans, in memory. Behaves like PdoPlanHistory (both pass
 * LoggingRepositoriesContract).
 */
final class InMemoryPlanHistory implements PlanHistory, InMemoryState
{
    /** @var array<string, PlanTerms> By user id and valid-from date. */
    private array $entries = [];

    public function __construct(?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function record(PlanTerms $terms): void
    {
        $this->entries["{$terms->userId->value} {$terms->validFrom->value}"] = $terms;
    }

    public function ofUser(UserId $userId): array
    {
        $entries = array_values(array_filter($this->entries, static fn (PlanTerms $terms): bool => $terms->userId->equals($userId)));
        usort($entries, static fn (PlanTerms $a, PlanTerms $b): int => $a->validFrom->value <=> $b->validFrom->value);

        return $entries;
    }

    public function snapshot(): array
    {
        return $this->entries;
    }

    public function restore(array $snapshot): void
    {
        $this->entries = [];
        foreach ($snapshot as $key => $terms) {
            \assert($terms instanceof PlanTerms);
            $this->entries[(string) $key] = $terms;
        }
    }
}
