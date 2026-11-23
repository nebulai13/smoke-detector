<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Friends\Block;
use SmokeDetector\Application\Friends\Blocks;

/**
 * Who has blocked whom, in memory. Behaves like PdoBlocks (both pass FriendRepositoriesContract).
 */
final class InMemoryBlocks implements Blocks, InMemoryState
{
    /** @var array<string, Block> By blocker id and blocked id. */
    private array $blocks = [];

    public function __construct(?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function add(Block $block): void
    {
        $this->blocks["{$block->blocker->value} {$block->blocked->value}"] ??= $block;
    }

    public function remove(UserId $blocker, UserId $blocked): bool
    {
        $existed = $this->isBlocked($blocker, $blocked);
        unset($this->blocks["{$blocker->value} {$blocked->value}"]);

        return $existed;
    }

    public function isBlocked(UserId $blocker, UserId $blocked): bool
    {
        return isset($this->blocks["{$blocker->value} {$blocked->value}"]);
    }

    public function between(UserId $a, UserId $b): bool
    {
        return $this->isBlocked($a, $b) || $this->isBlocked($b, $a);
    }

    public function byUser(UserId $blocker): array
    {
        $blocks = array_values(array_filter($this->blocks, static fn (Block $block): bool => $block->blocker->equals($blocker)));
        usort($blocks, static fn (Block $a, Block $b): int => [$a->blockedAt, $a->blocked->value] <=> [$b->blockedAt, $b->blocked->value]);

        return $blocks;
    }

    /**
     * @return list<Block> Everything stored: for the tests.
     */
    public function all(): array
    {
        return array_values($this->blocks);
    }

    public function snapshot(): array
    {
        return $this->blocks;
    }

    public function restore(array $snapshot): void
    {
        $this->blocks = [];
        foreach ($snapshot as $key => $block) {
            \assert($block instanceof Block);
            $this->blocks[(string) $key] = $block;
        }
    }
}
