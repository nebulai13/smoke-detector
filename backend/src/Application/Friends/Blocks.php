<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use SmokeDetector\Application\Account\UserId;

/**
 * Who has blocked whom (HLD §4.3 "blocks"). A block has a direction.
 */
interface Blocks
{
    /**
     * Stores the block. When the blocker has blocked that user already, the first block stays as it
     * was, with its time.
     */
    public function add(Block $block): void;

    /**
     * Lifts the block of $blocker against $blocked, and only that one: a block in the other
     * direction stays.
     *
     * @return bool Whether there was such a block.
     */
    public function remove(UserId $blocker, UserId $blocked): bool;

    /**
     * Whether $blocker has blocked $blocked (one direction).
     */
    public function isBlocked(UserId $blocker, UserId $blocked): bool;

    /**
     * Whether one of the two has blocked the other (either direction).
     */
    public function between(UserId $a, UserId $b): bool;

    /**
     * @return list<Block> The blocks the user has set, the oldest first; in the same second, the
     *                     smaller id (as text) of the blocked user first. Not the blocks of others
     *                     against the user.
     */
    public function byUser(UserId $blocker): array;
}
