<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The day of the last session before joining (HLD §4.3 "plans", §4.10, SRS FR-GAME-09, decided by
 * the owner on 2026-11-24): the user's local day, for somebody who quit before joining. NULL when the
 * user names none, which every plan made before this column did not.
 */
final class AddLastSessionBeforeToPlans extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            ALTER TABLE `plans` ADD COLUMN IF NOT EXISTS `last_session_before` DATE NULL
            SQL);
    }
}
