<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Database;

use PDOException;
use Psr\Log\LoggerInterface;
use SmokeDetector\Application\Health\DatabaseProbe;

/**
 * Asks the database for a trivial answer, to tell whether it is there.
 */
final readonly class PdoDatabaseProbe implements DatabaseProbe
{
    public function __construct(
        private Database $database,
        private LoggerInterface $logger,
    ) {
    }

    public function isReachable(): bool
    {
        try {
            $statement = $this->database->pdo()->query('SELECT 1');

            return $statement !== false && $statement->fetchColumn() === 1;
        } catch (DatabaseConnectionFailed | PDOException $failure) {
            // The driver's message names host and user, never the password.
            $cause = $failure->getPrevious() ?? $failure;
            $this->logger->error('The database cannot be reached: ' . $cause->getMessage());

            return false;
        }
    }
}
