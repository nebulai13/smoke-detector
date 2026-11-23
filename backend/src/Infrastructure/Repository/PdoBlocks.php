<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Friends\Block;
use SmokeDetector\Application\Friends\Blocks;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * Who has blocked whom, in the `blocks` table.
 */
final readonly class PdoBlocks implements Blocks
{
    public function __construct(private Sql $sql)
    {
    }

    public function add(Block $block): void
    {
        // Blocked already: the first block stays as it was. Not INSERT IGNORE, which would hide
        // every other error as well.
        $this->sql->execute(
            'INSERT INTO blocks (blocker_id, blocked_id, created_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE created_at = created_at',
            [$block->blocker->value, $block->blocked->value, Row::sqlTime($block->blockedAt)],
        );
    }

    public function remove(UserId $blocker, UserId $blocked): bool
    {
        return $this->sql->execute('DELETE FROM blocks WHERE blocker_id = ? AND blocked_id = ?', [$blocker->value, $blocked->value]) > 0;
    }

    public function isBlocked(UserId $blocker, UserId $blocked): bool
    {
        return $this->sql->value('SELECT 1 FROM blocks WHERE blocker_id = ? AND blocked_id = ?', [$blocker->value, $blocked->value]) !== null;
    }

    public function between(UserId $a, UserId $b): bool
    {
        return $this->isBlocked($a, $b) || $this->isBlocked($b, $a);
    }

    public function byUser(UserId $blocker): array
    {
        $blocks = [];
        foreach ($this->sql->rows('SELECT blocked_id, created_at FROM blocks WHERE blocker_id = ?', [$blocker->value]) as $columns) {
            $row = new Row($columns);
            $blocks[] = new Block($blocker, UserId::fromString($row->string('blocked_id')), $row->time('created_at'));
        }
        // Sorted here: MariaDB orders its UUID type in another way than the ids as text.
        usort(
            $blocks,
            static fn (Block $x, Block $y): int => $x->blockedAt <=> $y->blockedAt ?: strcmp($x->blocked->value, $y->blocked->value),
        );

        return $blocks;
    }
}
