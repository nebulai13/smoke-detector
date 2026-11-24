<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Feed\Reaction;
use SmokeDetector\Application\Feed\Reactions;

/**
 * The reactions to feed entries, in memory. Behaves like PdoReactions (both pass ReactionsContract).
 */
final class InMemoryReactions implements Reactions, InMemoryState
{
    /** @var array<string, Reaction> By event id and user id, in the order they were first stored. */
    private array $reactions = [];

    public function __construct(?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function find(string $eventId, UserId $userId): ?Reaction
    {
        return $this->reactions["{$eventId} {$userId->value}"] ?? null;
    }

    public function save(Reaction $reaction): bool
    {
        $stored = $this->find($reaction->eventId, $reaction->userId);
        // A reaction that is there changes its key and keeps its time.
        $this->reactions["{$reaction->eventId} {$reaction->userId->value}"] = $stored === null
            ? $reaction
            : new Reaction($stored->eventId, $stored->userId, $reaction->key, $stored->createdAt);

        return $stored === null;
    }

    public function ofEvents(array $eventIds): array
    {
        $byEvent = [];
        foreach ($this->sorted() as $reaction) {
            if (in_array($reaction->eventId, $eventIds, true)) {
                $byEvent[$reaction->eventId][] = $reaction;
            }
        }
        ksort($byEvent);

        return $byEvent;
    }

    public function countSentBy(UserId $userId): int
    {
        return count($this->sentBy($userId));
    }

    public function sentBy(UserId $userId): array
    {
        return array_values(array_filter($this->sorted(), static fn (Reaction $reaction): bool => $reaction->userId->equals($userId)));
    }

    /**
     * @return list<Reaction> Everything stored, in the order it was first stored: for the tests.
     */
    public function all(): array
    {
        return array_values($this->reactions);
    }

    public function snapshot(): array
    {
        return $this->reactions;
    }

    public function restore(array $snapshot): void
    {
        $this->reactions = [];
        foreach ($snapshot as $key => $reaction) {
            \assert($reaction instanceof Reaction);
            $this->reactions[(string) $key] = $reaction;
        }
    }

    /**
     * @return list<Reaction> Oldest first, then by event and user.
     */
    private function sorted(): array
    {
        $reactions = array_values($this->reactions);
        usort($reactions, static fn (Reaction $a, Reaction $b): int => [$a->createdAt, $a->eventId, $a->userId->value] <=> [$b->createdAt, $b->eventId, $b->userId->value]);

        return $reactions;
    }
}
