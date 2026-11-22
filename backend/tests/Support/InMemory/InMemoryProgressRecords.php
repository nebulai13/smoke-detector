<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use Closure;
use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Day\ProgressRecord;
use SmokeDetector\Application\Day\ProgressRecords;

/**
 * The progress of the users, in memory. Behaves like PdoProgressRecords (both pass
 * LoggingRepositoriesContract).
 */
final class InMemoryProgressRecords implements ProgressRecords, InMemoryState
{
    /** Called after every save(): a test lets time pass here, as real work would take it. */
    public ?Closure $afterSave = null;

    /** How often due() was asked. */
    public int $dueCalls = 0;

    /** @var array<string, ProgressRecord> By user id. */
    private array $records = [];

    public function __construct(?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function find(UserId $userId): ?ProgressRecord
    {
        return $this->records[$userId->value] ?? null;
    }

    public function save(ProgressRecord $record): void
    {
        $this->records[$record->userId->value] = $record;
        if ($this->afterSave !== null) {
            ($this->afterSave)();
        }
    }

    public function due(DateTimeImmutable $now, int $limit): array
    {
        $this->dueCalls++;
        $earliest = static fn (ProgressRecord $record): DateTimeImmutable => min($record->finalizeDueAt, $record->baselineDueAt);
        $due = array_values(array_filter($this->records, static fn (ProgressRecord $record): bool => $earliest($record) <= $now));
        usort(
            $due,
            static fn (ProgressRecord $a, ProgressRecord $b): int => [$earliest($a), $a->userId->value] <=> [$earliest($b), $b->userId->value],
        );

        return array_map(static fn (ProgressRecord $record): UserId => $record->userId, array_slice($due, 0, $limit));
    }

    /**
     * What account deletion does to the table.
     */
    public function eraseOfUser(UserId $userId): void
    {
        unset($this->records[$userId->value]);
    }

    public function snapshot(): array
    {
        return $this->records;
    }

    public function restore(array $snapshot): void
    {
        $this->records = [];
        foreach ($snapshot as $userId => $record) {
            \assert($record instanceof ProgressRecord);
            $this->records[(string) $userId] = $record;
        }
    }
}
