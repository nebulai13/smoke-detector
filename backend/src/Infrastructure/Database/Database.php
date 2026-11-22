<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Database;

use PDO;

/**
 * The database connection of the current request or command.
 *
 * Repositories and the transaction runner ask for the connection each time they use it instead of
 * holding a PDO object, so that they all work on the same connection (and therefore in the same
 * transaction), and so that nothing connects before it is needed.
 */
interface Database
{
    /**
     * The shared connection. Opened on the first call.
     *
     * @throws DatabaseConnectionFailed When the server cannot be reached or refuses the login.
     */
    public function pdo(): PDO;
}
