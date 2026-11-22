<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Database;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use SmokeDetector\Application\TemporarilyUnavailable;

/**
 * Runs prepared statements on the shared connection. This is what repositories use: there is no ORM.
 *
 * Values only ever travel as bound parameters. The SQL text must be a constant of the calling code
 * and never contain a value.
 *
 * A statement the database gives up for now (deadlock, lock wait timeout) is reported as
 * TemporarilyUnavailable. Every other error stays the PDOException it is.
 */
final readonly class Sql
{
    /** MariaDB gave this transaction up to end a deadlock, and has rolled it back. */
    private const int DEADLOCK = 1213;

    /** A row stayed locked by another transaction for longer than innodb_lock_wait_timeout. */
    private const int LOCK_WAIT_TIMEOUT = 1205;

    /**
     * "Record has changed since last read": the transaction read a row and then tried to lock or
     * change it after another transaction had changed it (innodb_snapshot_isolation, on by default
     * since MariaDB 11.6.2). Use cases avoid it by reading before their transaction begins; this is
     * the net for a case nobody thought of.
     */
    private const int RECORD_CHANGED = 1020;

    public function __construct(private Database $database)
    {
    }

    /**
     * Runs a statement that changes data.
     *
     * @param array<int|string, bool|float|int|string|null> $parameters By position (for "?") or by name (for ":name").
     *
     * @return int The number of rows the statement changed.
     */
    public function execute(string $sql, array $parameters = []): int
    {
        return $this->run($sql, $parameters)->rowCount();
    }

    /**
     * @param array<int|string, bool|float|int|string|null> $parameters
     *
     * @return list<array<string, mixed>> Every row as column name => value.
     */
    public function rows(string $sql, array $parameters = []): array
    {
        $rows = [];
        foreach ($this->run($sql, $parameters)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $named = [];
            if (is_array($row)) {
                foreach ($row as $column => $value) {
                    $named[(string) $column] = $value;
                }
            }
            $rows[] = $named;
        }

        return $rows;
    }

    /**
     * @param array<int|string, bool|float|int|string|null> $parameters
     *
     * @return array<string, mixed>|null The first row, or null when there is none.
     */
    public function row(string $sql, array $parameters = []): ?array
    {
        return $this->rows($sql, $parameters)[0] ?? null;
    }

    /**
     * @param array<int|string, bool|float|int|string|null> $parameters
     *
     * @return mixed The first column of the first row, or null when there is no row.
     */
    public function value(string $sql, array $parameters = []): mixed
    {
        return $this->column($sql, $parameters)[0] ?? null;
    }

    /**
     * @param array<int|string, bool|float|int|string|null> $parameters
     *
     * @return list<mixed> The first column of every row.
     */
    public function column(string $sql, array $parameters = []): array
    {
        return array_values($this->run($sql, $parameters)->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param array<int|string, bool|float|int|string|null> $parameters
     */
    private function run(string $sql, array $parameters): PDOStatement
    {
        $statement = $this->database->pdo()->prepare($sql);
        // The connection reports errors as exceptions, so false cannot happen. The type says it can.
        if ($statement === false) {
            throw new RuntimeException('The statement could not be prepared.');
        }

        foreach ($parameters as $name => $value) {
            // Positions count from 1. Each value is bound with its own type: with real prepared
            // statements PDO would otherwise send false as an empty string.
            $statement->bindValue(
                is_int($name) ? $name + 1 : $name,
                match (true) {
                    is_bool($value) => (int) $value,
                    // 17 significant digits are what it takes to name every float exactly. A plain
                    // cast to text keeps 14 (the "precision" setting) and would store another number.
                    is_float($value) => sprintf('%.17G', $value),
                    default => $value,
                },
                match (true) {
                    $value === null => PDO::PARAM_NULL,
                    is_bool($value), is_int($value) => PDO::PARAM_INT,
                    default => PDO::PARAM_STR,
                },
            );
        }
        try {
            $statement->execute();
        } catch (PDOException $failure) {
            // Not a bug and not the client's fault: the caller rolls back, and the client may try again.
            if (in_array($failure->errorInfo[1] ?? null, [self::DEADLOCK, self::LOCK_WAIT_TIMEOUT, self::RECORD_CHANGED], true)) {
                throw new TemporarilyUnavailable(1, $failure);
            }

            throw $failure;
        }

        return $statement;
    }
}
