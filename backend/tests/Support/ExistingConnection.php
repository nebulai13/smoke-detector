<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support;

use PDO;
use SmokeDetector\Infrastructure\Database\Database;

/**
 * Wraps a connection a test opened by hand, so that Sql can run statements on it.
 */
final readonly class ExistingConnection implements Database
{
    public function __construct(private PDO $pdo)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
