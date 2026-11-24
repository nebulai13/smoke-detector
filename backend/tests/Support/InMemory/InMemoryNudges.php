<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Nudge\Nudge;
use SmokeDetector\Application\Nudge\Nudges;
use SmokeDetector\Application\Time\LocalDate;

/**
 * The nudges between friends, in memory. Behaves like PdoNudges (both pass NudgesContract).
 */
final class InMemoryNudges implements Nudges, InMemoryState
{
    /** @var array<string, Nudge> By sender, receiver and day, in the order they were stored. */
    private array $nudges = [];

    public function __construct(?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function find(UserId $from, UserId $to, LocalDate $day): ?Nudge
    {
        return $this->nudges["{$from->value} {$to->value} {$day->value}"] ?? null;
    }

    public function add(Nudge $nudge): bool
    {
        if ($this->find($nudge->from, $nudge->to, $nudge->day) !== null) {
            // One nudge per friend and day: the first stays.
            return false;
        }
        $this->nudges["{$nudge->from->value} {$nudge->to->value} {$nudge->day->value}"] = $nudge;

        return true;
    }

    public function deliveredTo(UserId $to, DateTimeImmutable $since): array
    {
        return array_values(array_filter(
            $this->sorted(),
            static fn (Nudge $nudge): bool => $nudge->to->equals($to) && $nudge->delivered && $nudge->createdAt >= $since,
        ));
    }

    public function sentBy(UserId $from): array
    {
        return array_values(array_filter($this->sorted(), static fn (Nudge $nudge): bool => $nudge->from->equals($from)));
    }

    public function receivedBy(UserId $to): array
    {
        return array_values(array_filter($this->sorted(), static fn (Nudge $nudge): bool => $nudge->to->equals($to)));
    }

    /**
     * @return list<Nudge> Everything stored, in the order it was stored: for the tests.
     */
    public function all(): array
    {
        return array_values($this->nudges);
    }

    public function snapshot(): array
    {
        return $this->nudges;
    }

    public function restore(array $snapshot): void
    {
        $this->nudges = [];
        foreach ($snapshot as $key => $nudge) {
            \assert($nudge instanceof Nudge);
            $this->nudges[(string) $key] = $nudge;
        }
    }

    /**
     * @return list<Nudge> Oldest first, then by sender and receiver.
     */
    private function sorted(): array
    {
        $nudges = array_values($this->nudges);
        usort($nudges, static fn (Nudge $a, Nudge $b): int => [$a->createdAt, $a->from->value, $a->to->value] <=> [$b->createdAt, $b->from->value, $b->to->value]);

        return $nudges;
    }
}
