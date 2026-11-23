<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Database;

use PDOException;
use RuntimeException;

/**
 * The database server could not be reached, refused the login or rejected the session set-up.
 *
 * The message is fixed, so it can be shown or logged anywhere. The driver's own message (host, user
 * name, never the password) is in the previous exception.
 */
final class DatabaseConnectionFailed extends RuntimeException
{
    public function __construct(PDOException $cause)
    {
        parent::__construct('Cannot connect to the database.', 0, $cause);
    }
}
