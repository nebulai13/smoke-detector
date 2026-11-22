<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use DateTimeImmutable;
use LogicException;
use SmokeDetector\Application\RateLimit\RateLimitCounters;
use SmokeDetector\Infrastructure\Database\Database;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The rate limit counters in the `rate_limits` table: one row per key and window.
 */
final readonly class PdoRateLimitCounters implements RateLimitCounters
{
    public function __construct(
        private Sql $sql,
        private Database $database,
    ) {
    }

    public function increment(string $key, DateTimeImmutable $windowStart): int
    {
        $this->assertNoTransaction();

        // One statement: the row lock makes concurrent hits queue up, none is lost.
        $this->sql->execute(
            'INSERT INTO rate_limits (`key`, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1',
            [$key, Row::sqlTime($windowStart)],
        );

        // Read after our own hit was stored. A hit of another request in between makes the number
        // larger, which errs on the strict side.
        $hits = $this->sql->value(
            'SELECT hits FROM rate_limits WHERE `key` = ? AND window_start = ?',
            [$key, Row::sqlTime($windowStart)],
        );

        // Without a row (the cleanup ran in between) the hit counts as over every limit.
        return is_int($hits) ? $hits : PHP_INT_MAX;
    }

    public function decrement(string $key, DateTimeImmutable $windowStart): void
    {
        $this->assertNoTransaction();

        $this->sql->execute(
            'UPDATE rate_limits SET hits = hits - 1 WHERE `key` = ? AND window_start = ? AND hits > 0',
            [$key, Row::sqlTime($windowStart)],
        );
    }

    public function deleteWindowsStartedBefore(DateTimeImmutable $before): int
    {
        return $this->sql->execute('DELETE FROM rate_limits WHERE window_start < ?', [Row::sqlTime($before)]);
    }

    public function deleteKey(string $key): int
    {
        $this->assertNoTransaction();

        return $this->sql->execute('DELETE FROM rate_limits WHERE `key` = ?', [$key]);
    }

    public function deleteKeysEndingWith(string $suffix): int
    {
        $this->assertNoTransaction();

        // RIGHT() and not LIKE: the suffix is text, and "%" or "_" in it must not match anything
        // else. No index helps here, which is fine: the table is small and this runs once per
        // deleted account.
        return $this->sql->execute(
            'DELETE FROM rate_limits WHERE RIGHT(`key`, ?) = ?',
            [mb_strlen($suffix, 'UTF-8'), $suffix],
        );
    }

    /**
     * A hit counted inside a transaction would vanish with its rollback, exactly when it matters:
     * for the failed attempt.
     */
    private function assertNoTransaction(): void
    {
        if ($this->database->pdo()->inTransaction()) {
            throw new LogicException('Rate limit counters must be changed outside the use case\'s transaction.');
        }
    }
}
