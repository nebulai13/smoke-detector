<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Friends\FriendLinks;
use SmokeDetector\Application\Friends\Friendship;

/**
 * Friend requests and friendships, in memory. Behaves like PdoFriendLinks (both pass
 * FriendRepositoriesContract).
 */
final class InMemoryFriendLinks implements FriendLinks, InMemoryState
{
    /** @var array<string, Friendship> By the two user ids, the smaller one first. */
    private array $links = [];

    public function __construct(?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function between(UserId $a, UserId $b): ?Friendship
    {
        return $this->links[self::key($a, $b)] ?? null;
    }

    public function save(Friendship $friendship): void
    {
        $this->links[self::key($friendship->requester, $friendship->recipient)] = $friendship;
    }

    public function delete(UserId $a, UserId $b): void
    {
        unset($this->links[self::key($a, $b)]);
    }

    public function ofUser(UserId $userId): array
    {
        $links = array_values(array_filter(
            $this->links,
            static fn (Friendship $link): bool => $link->requester->equals($userId) || $link->recipient->equals($userId),
        ));
        usort(
            $links,
            static fn (Friendship $a, Friendship $b): int => [$a->requestedAt, $a->other($userId)->value] <=> [$b->requestedAt, $b->other($userId)->value],
        );

        return $links;
    }

    /**
     * @return list<Friendship> Everything stored: for the tests.
     */
    public function all(): array
    {
        return array_values($this->links);
    }

    public function snapshot(): array
    {
        return $this->links;
    }

    public function restore(array $snapshot): void
    {
        $this->links = [];
        foreach ($snapshot as $key => $link) {
            \assert($link instanceof Friendship);
            $this->links[(string) $key] = $link;
        }
    }

    private static function key(UserId $a, UserId $b): string
    {
        $ids = [$a->value, $b->value];
        sort($ids);

        return implode(' ', $ids);
    }
}
