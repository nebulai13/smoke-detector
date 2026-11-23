<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Database;

use PDO;

/**
 * Opens the connection when it is first asked for and keeps it for the rest of the request.
 *
 * Lazy, because the hosting plan allows only 20 connections (HLD §11): a request that is rejected by
 * validation, or that is answered without the database, must not take one. Not readonly, because it
 * remembers the connection. One instance lives in the container per request.
 */
final class LazyPdoDatabase implements Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly ConnectionFactory $connections)
    {
    }

    public function pdo(): PDO
    {
        // A failed attempt leaves null behind, so the next call tries again.
        return $this->pdo ??= $this->connections->connect();
    }
}
