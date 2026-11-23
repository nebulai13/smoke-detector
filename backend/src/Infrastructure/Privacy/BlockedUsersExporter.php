<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Privacy;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Friends\Block;
use SmokeDetector\Application\Privacy\ExportFormat;
use SmokeDetector\Application\Privacy\UserDataExporter;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoBlocks;

/**
 * The `blocks` table in the data export (schema ExportedBlock): the users the user has blocked,
 * oldest block first. Not the blocks of others against the user: nobody learns who has blocked
 * them, not even from their own export (HLD §4.7 "Blocks").
 */
final readonly class BlockedUsersExporter implements UserDataExporter
{
    public function __construct(private Sql $sql)
    {
    }

    public function export(UserId $userId): array
    {
        return array_map(
            static fn (Block $block): array => [
                'userId' => $block->blocked->value,
                'blockedAt' => ExportFormat::time($block->blockedAt),
            ],
            (new PdoBlocks($this->sql))->byUser($userId),
        );
    }
}
