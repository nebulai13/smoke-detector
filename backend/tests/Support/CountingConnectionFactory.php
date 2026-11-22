<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support;

use PDO;
use SmokeDetector\Infrastructure\Database\ConnectionFactory;

/**
 * Counts how many connections were opened, for tests about connecting late or only once.
 */
final class CountingConnectionFactory implements ConnectionFactory
{
    public int $connections = 0;

    public function __construct(private readonly ConnectionFactory $inner)
    {
    }

    public function connect(): PDO
    {
        $this->connections++;

        return $this->inner->connect();
    }
}
