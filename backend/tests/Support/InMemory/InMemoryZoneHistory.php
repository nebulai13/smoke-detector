<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Application\Time\ZoneChange;
use SmokeDetector\Application\Time\ZoneHistory;

/**
 * The history of the time zones, in memory. Behaves like PdoZoneHistory (both pass
 * LoggingRepositoriesContract).
 */
final class InMemoryZoneHistory implements ZoneHistory, InMemoryState
{
    /** @var array<string, array<string, ZoneChange>> By user id, then by valid-from date. */
    private array $changes = [];

    public function __construct(?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function record(UserId $userId, ZoneChange $change): void
    {
        $this->changes[$userId->value][$change->validFrom->value] = $change;
    }

    public function dropAfter(UserId $userId, LocalDate $date): void
    {
        foreach (array_keys($this->changes[$userId->value] ?? []) as $validFrom) {
            if ($validFrom > $date->value) {
                unset($this->changes[$userId->value][$validFrom]);
            }
        }
    }

    public function ofUser(UserId $userId): array
    {
        $changes = $this->changes[$userId->value] ?? [];
        ksort($changes);

        return array_values($changes);
    }

    public function snapshot(): array
    {
        return $this->changes;
    }

    public function restore(array $snapshot): void
    {
        $this->changes = [];
        foreach ($snapshot as $userId => $changes) {
            \assert(is_array($changes));
            foreach ($changes as $validFrom => $change) {
                \assert($change instanceof ZoneChange);
                $this->changes[(string) $userId][(string) $validFrom] = $change;
            }
        }
    }
}
