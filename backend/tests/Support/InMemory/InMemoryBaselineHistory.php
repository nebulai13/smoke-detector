<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Plan\BaselineAlreadyRecorded;
use SmokeDetector\Application\Plan\BaselineEntry;
use SmokeDetector\Application\Plan\BaselineHistory;
use SmokeDetector\Domain\Plan\BaselineSource;

/**
 * The baseline history, in memory. Behaves like PdoBaselineHistory (both pass PlanRepositoriesContract).
 */
final class InMemoryBaselineHistory implements BaselineHistory, InMemoryState
{
    /** @var list<BaselineEntry> In the order they were appended. */
    private array $entries = [];

    public function __construct(?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function append(BaselineEntry $entry): void
    {
        foreach ($this->entries as $existing) {
            if ($existing->userId->equals($entry->userId) && $existing->validFrom->equals($entry->validFrom)) {
                throw new BaselineAlreadyRecorded();
            }
        }

        $this->entries[] = $entry;
    }

    public function latest(UserId $userId): ?BaselineEntry
    {
        $entries = $this->ofUser($userId);

        return $entries === [] ? null : $entries[count($entries) - 1];
    }

    public function starting(UserId $userId): ?BaselineEntry
    {
        foreach ($this->ofUser($userId) as $entry) {
            if ($entry->source === BaselineSource::Onboarding) {
                return $entry;
            }
        }

        return null;
    }

    public function ofUser(UserId $userId): array
    {
        $entries = array_values(array_filter(
            $this->entries,
            static fn (BaselineEntry $entry): bool => $entry->userId->equals($userId),
        ));
        usort($entries, static fn (BaselineEntry $a, BaselineEntry $b): int => $a->validFrom->value <=> $b->validFrom->value);

        return $entries;
    }

    /**
     * @return list<BaselineEntry> Of all users, in the order they were appended.
     */
    public function all(): array
    {
        return $this->entries;
    }

    public function snapshot(): array
    {
        return $this->entries;
    }

    public function restore(array $snapshot): void
    {
        $this->entries = array_values(array_filter(
            $snapshot,
            static fn (mixed $entry): bool => $entry instanceof BaselineEntry,
        ));
    }
}
