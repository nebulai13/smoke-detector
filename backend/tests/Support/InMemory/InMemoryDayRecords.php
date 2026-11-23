<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Day\DayRecord;
use SmokeDetector\Application\Day\DayRecords;
use SmokeDetector\Application\Time\LocalDate;

/**
 * The days, in memory. Behaves like PdoDayRecords (both pass LoggingRepositoriesContract).
 */
final class InMemoryDayRecords implements DayRecords, InMemoryState
{
    /** @var array<string, DayRecord> By user id and date. */
    private array $days = [];

    public function __construct(?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function find(UserId $userId, LocalDate $date): ?DayRecord
    {
        return $this->days["{$userId->value} {$date->value}"] ?? null;
    }

    public function save(DayRecord $day): void
    {
        $this->days["{$day->userId->value} {$day->date->value}"] = $day;
    }

    public function between(UserId $userId, LocalDate $from, LocalDate $to): array
    {
        $days = array_values(array_filter(
            $this->days,
            static fn (DayRecord $day): bool => $day->userId->equals($userId) && $day->date->value >= $from->value && $day->date->value <= $to->value,
        ));
        usort($days, static fn (DayRecord $a, DayRecord $b): int => $a->date->value <=> $b->date->value);

        return $days;
    }

    /**
     * @return list<DayRecord> Of one user, oldest first.
     */
    public function ofUser(UserId $userId): array
    {
        return $this->between($userId, LocalDate::fromString('0001-01-01'), LocalDate::fromString('9999-12-31'));
    }

    public function snapshot(): array
    {
        return $this->days;
    }

    public function restore(array $snapshot): void
    {
        $this->days = [];
        foreach ($snapshot as $key => $day) {
            \assert($day instanceof DayRecord);
            $this->days[(string) $key] = $day;
        }
    }
}
