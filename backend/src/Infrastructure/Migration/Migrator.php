<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Migration;

/**
 * Brings the database schema up to date.
 */
interface Migrator
{
    /**
     * Runs every migration that has not run on this schema yet, oldest first.
     *
     * @return list<string> The migrations that were run now, as "version ClassName". Empty when the
     *                      schema was already up to date.
     */
    public function migrate(): array;
}
