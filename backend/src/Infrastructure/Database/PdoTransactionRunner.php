<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Database;

use LogicException;
use PDO;
use SmokeDetector\Application\TransactionRunner;
use Throwable;

/**
 * Transactions on the shared connection of the request.
 */
final readonly class PdoTransactionRunner implements TransactionRunner
{
    public function __construct(private Database $database)
    {
    }

    public function run(callable $work): mixed
    {
        $pdo = $this->database->pdo();

        // MariaDB has no real nested transactions. Pretending (with savepoints or a counter) would let
        // an inner "commit" be undone by the outer rollback, so nesting is refused. Thrown inside the
        // outer run(), this rolls the outer transaction back as well.
        if ($this->inTransaction($pdo)) {
            throw new LogicException('A transaction is already running. Only the use case starts one.');
        }

        $pdo->beginTransaction();
        try {
            $result = $work();

            // A statement that changes the schema, or a commit by the work itself, ends the transaction
            // behind our back. What came after it was then stored without any protection.
            if (!$this->inTransaction($pdo)) {
                throw new LogicException('The work ended the transaction itself. Only the transaction runner commits.');
            }
            $pdo->commit();
        } catch (Throwable $failure) {
            if ($this->inTransaction($pdo)) {
                $pdo->rollBack();
            }

            throw $failure;
        }

        return $result;
    }

    /**
     * @phpstan-impure The answer changes with the statements the connection has run in between.
     */
    private function inTransaction(PDO $pdo): bool
    {
        return $pdo->inTransaction();
    }
}
