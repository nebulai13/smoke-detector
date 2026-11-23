<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Database;

use PDO;
use Pdo\Mysql;
use PDOException;
use SmokeDetector\Config\DatabaseSettings;

/**
 * Opens MariaDB connections that behave the same on every server.
 *
 * The hosting server defaults to a non-strict sql_mode, utf8mb3 and CET (HLD §11): text that is too
 * long is cut off, emoji turn into "?", and NOW() is local time. So nothing is left to the server
 * defaults: every connection is set to UTC, strict mode and utf8mb4 before it is handed out.
 */
final readonly class PdoConnectionFactory implements ConnectionFactory
{
    /**
     * STRICT_ALL_TABLES makes the server reject a value it would otherwise bend (cut, round, zero).
     * The others close the remaining gaps: dates that do not exist, division by zero, a silently
     * replaced storage engine and GROUP BY queries that pick arbitrary rows.
     */
    private const string SQL_MODE = 'STRICT_ALL_TABLES,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,'
        . 'NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY';

    /** Seconds to wait for the server. A request must fail fast, not hang, when the database is down. */
    private const int CONNECT_TIMEOUT_SECONDS = 5;

    public function __construct(private DatabaseSettings $settings)
    {
    }

    public function connect(): PDO
    {
        // DatabaseSettings restricts host and schema name to characters that cannot add a DSN option.
        // The charset in the DSN also tells the driver how to escape, SET NAMES below fixes the collation.
        $dsn = "mysql:host={$this->settings->host};port={$this->settings->port};"
            . "dbname={$this->settings->name};charset=utf8mb4";

        try {
            $pdo = new PDO($dsn, $this->settings->user, $this->settings->password->reveal(), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Real prepared statements: values never become part of the SQL text, and integers
                // come back as integers.
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                // The hosting plan allows 20 connections per user (HLD §11). Persistent connections
                // would hold them between requests, and would carry session state from one to the next.
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                // One statement per call, so injected text can never append a statement of its own.
                Mysql::ATTR_MULTI_STATEMENTS => false,
            ]);

            // Same collation as the tables, which declare utf8mb4_unicode_ci explicitly.
            $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec("SET SESSION time_zone = '+00:00', SESSION sql_mode = '" . self::SQL_MODE . "'");
        } catch (PDOException $exception) {
            throw new DatabaseConnectionFailed($exception);
        }

        return $pdo;
    }
}
