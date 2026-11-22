<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Database;

use PDO;

/**
 * Opens connections to the application's database.
 */
interface ConnectionFactory
{
    /**
     * Opens a new connection that is ready for use: UTC, strict mode, utf8mb4.
     *
     * @throws DatabaseConnectionFailed When the server cannot be reached or refuses the login.
     */
    public function connect(): PDO;
}
