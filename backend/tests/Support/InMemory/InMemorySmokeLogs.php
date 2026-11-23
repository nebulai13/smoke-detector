<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Logging\SmokeLog;
use SmokeDetector\Application\Logging\SmokeLogs;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\Time\LocalDate;

/**
 * The logs, in memory. Behaves like PdoSmokeLogs (both pass LoggingRepositoriesContract).
 */
final class InMemorySmokeLogs implements SmokeLogs, InMemoryState
{
    /** @var array<string, SmokeLog> By id. */
    private array $logs = [];

    public function __construct(?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function find(string $id): ?SmokeLog
    {
        return $this->logs[$id] ?? null;
    }

    public function save(SmokeLog $log): bool
    {
        $stored = $this->logs[$log->id] ?? null;
        if ($stored !== null && !$stored->userId->equals($log->userId)) {
            return false;
        }

        $this->logs[$log->id] = new SmokeLog(
            $log->id,
            $log->userId,
            $log->smokedAt,
            $log->localDate,
            $log->productType,
            $log->count,
            $stored === null ? $log->createdAt : $stored->createdAt,
            $log->updatedAt,
            $log->deletedAt,
        );

        return true;
    }

    public function sessionsOn(UserId $userId, LocalDate $date): int
    {
        $sessions = 0;
        foreach ($this->logs as $log) {
            if ($log->userId->equals($userId) && $log->localDate->equals($date) && $log->deletedAt === null) {
                $sessions += $log->count;
            }
        }

        return $sessions;
    }

    public function lastSessionAt(UserId $userId): ?DateTimeImmutable
    {
        $last = null;
        foreach ($this->logs as $log) {
            if ($log->userId->equals($userId) && $log->deletedAt === null && ($last === null || $log->smokedAt > $last)) {
                $last = $log->smokedAt;
            }
        }

        return $last;
    }

    public function productsOf(UserId $userId): array
    {
        $used = [];
        foreach ($this->logs as $log) {
            if ($log->userId->equals($userId) && $log->deletedAt === null) {
                $used[$log->productType->value] = true;
            }
        }

        return array_values(array_filter(ProductType::cases(), static fn (ProductType $type): bool => isset($used[$type->value])));
    }

    /**
     * @return list<SmokeLog> Of all users, in the order they were first saved.
     */
    public function all(): array
    {
        return array_values($this->logs);
    }

    public function snapshot(): array
    {
        return $this->logs;
    }

    public function restore(array $snapshot): void
    {
        $this->logs = [];
        foreach ($snapshot as $id => $log) {
            \assert($log instanceof SmokeLog);
            $this->logs[(string) $id] = $log;
        }
    }
}
