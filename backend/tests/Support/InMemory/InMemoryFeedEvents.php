<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Feed\FeedEvent;
use SmokeDetector\Application\Feed\FeedEvents;

/**
 * The entries of the activity feed, in memory. Behaves like PdoFeedEvents (both pass
 * FeedEventsContract).
 */
final class InMemoryFeedEvents implements FeedEvents, InMemoryState
{
    /** @var list<FeedEvent> In the order they were written. */
    private array $events = [];

    public function __construct(?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function append(FeedEvent $event): void
    {
        $this->events[] = $event;
    }

    public function find(string $id): ?FeedEvent
    {
        foreach ($this->events as $event) {
            if ($event->id === $id) {
                return $event;
            }
        }

        return null;
    }

    public function newestOf(array $userIds, int $limit): array
    {
        $wanted = array_map(static fn (UserId $userId): string => $userId->value, $userIds);
        $events = array_values(array_filter($this->events, static fn (FeedEvent $event): bool => in_array($event->userId->value, $wanted, true)));

        // The one written last comes first, whatever the clock said.
        return array_slice(array_reverse($events), 0, $limit);
    }

    public function ofUser(UserId $userId): array
    {
        return array_values(array_filter($this->events, static fn (FeedEvent $event): bool => $event->userId->equals($userId)));
    }

    /**
     * @return list<FeedEvent> Everything stored, in the order it was written: for the tests.
     */
    public function all(): array
    {
        return $this->events;
    }

    public function snapshot(): array
    {
        return $this->events;
    }

    public function restore(array $snapshot): void
    {
        $this->events = [];
        foreach ($snapshot as $event) {
            \assert($event instanceof FeedEvent);
            $this->events[] = $event;
        }
    }
}
