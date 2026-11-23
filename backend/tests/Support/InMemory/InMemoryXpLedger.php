<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use LogicException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Day\XpEvent;
use SmokeDetector\Application\Day\XpLedger;

/**
 * The XP ledger, in memory. Behaves like PdoXpLedger (both pass LoggingRepositoriesContract).
 */
final class InMemoryXpLedger implements XpLedger, InMemoryState
{
    /** @var array<string, XpEvent> By id. */
    private array $events = [];

    public function __construct(?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function append(XpEvent $event): void
    {
        if (isset($this->events[$event->id])) {
            throw new LogicException('The XP ledger already has an event with that id.');
        }

        $this->events[$event->id] = $event;
    }

    public function ofUser(UserId $userId): array
    {
        $events = array_values(array_filter($this->events, static fn (XpEvent $event): bool => $event->userId->equals($userId)));
        usort($events, static fn (XpEvent $a, XpEvent $b): int => [$a->date->value, $a->id] <=> [$b->date->value, $b->id]);

        return $events;
    }

    public function snapshot(): array
    {
        return $this->events;
    }

    public function restore(array $snapshot): void
    {
        $this->events = [];
        foreach ($snapshot as $id => $event) {
            \assert($event instanceof XpEvent);
            $this->events[(string) $id] = $event;
        }
    }
}
