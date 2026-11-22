<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Infrastructure\Database;

use LogicException;
use PDOException;
use RuntimeException;
use SmokeDetector\Infrastructure\Database\Database;
use SmokeDetector\Infrastructure\Database\LazyPdoDatabase;
use SmokeDetector\Infrastructure\Database\PdoConnectionFactory;
use SmokeDetector\Infrastructure\Database\PdoTransactionRunner;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Tests\Support\CountingConnectionFactory;
use SmokeDetector\Tests\Support\IntegrationTestCase;

/**
 * The transaction helper of the use cases: everything inside run() is stored together or not at all.
 *
 * The rate_limits table serves as the table to write to, because it is the only one so far.
 */
final class PdoTransactionRunnerTest extends IntegrationTestCase
{
    private Database $database;

    private PdoTransactionRunner $transactions;

    protected function setUp(): void
    {
        $this->database = new LazyPdoDatabase(new PdoConnectionFactory($this->settings()->database));
        $this->transactions = new PdoTransactionRunner($this->database);
    }

    public function test_the_work_is_committed_and_its_result_returned(): void
    {
        $result = $this->transactions->run(function (): string {
            $this->insert('a');
            $this->insert('b');

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(['a', 'b'], $this->keysSeenByAnotherSession());
        self::assertFalse($this->database->pdo()->inTransaction());
    }

    public function test_nothing_is_visible_to_other_sessions_before_the_work_is_finished(): void
    {
        $seenDuringTheWork = $this->transactions->run(function (): array {
            $this->insert('a');

            return $this->keysSeenByAnotherSession();
        });

        self::assertSame([], $seenDuringTheWork);
        self::assertSame(['a'], $this->keysSeenByAnotherSession());
    }

    public function test_a_failure_rolls_everything_back_and_is_passed_on_unchanged(): void
    {
        $failure = new RuntimeException('The second step failed.');

        try {
            $this->transactions->run(function () use ($failure): never {
                $this->insert('a');

                throw $failure;
            });
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame([], $this->keysSeenByAnotherSession());
        self::assertFalse($this->database->pdo()->inTransaction());
    }

    public function test_a_failing_statement_rolls_back_the_statements_before_it(): void
    {
        try {
            $this->transactions->run(function (): void {
                $this->insert('a');
                // The same primary key again.
                $this->insert('a');
            });
            self::fail('The duplicate row was accepted.');
        } catch (PDOException $exception) {
            self::assertSame('23000', $exception->getCode());
        }

        self::assertSame([], $this->keysSeenByAnotherSession());
    }

    public function test_the_connection_can_be_used_again_after_a_failure(): void
    {
        try {
            $this->transactions->run(static fn () => throw new RuntimeException('failed'));
        } catch (RuntimeException) {
            // Expected.
        }

        $this->transactions->run(fn () => $this->insert('after'));

        self::assertSame(['after'], $this->keysSeenByAnotherSession());
    }

    public function test_a_transaction_inside_a_transaction_is_a_programming_error(): void
    {
        // One use case owns one transaction (HLD §4.1). A nested one would commit or roll back only
        // in appearance, so it is refused, and the surrounding work is rolled back with it.
        try {
            $this->transactions->run(function (): void {
                $this->insert('outer');
                $this->transactions->run(fn () => $this->insert('inner'));
            });
            self::fail('The nested transaction was accepted.');
        } catch (LogicException $exception) {
            self::assertSame(
                'A transaction is already running. Only the use case starts one.',
                $exception->getMessage(),
            );
        }

        self::assertSame([], $this->keysSeenByAnotherSession());
        self::assertFalse($this->database->pdo()->inTransaction());
    }

    public function test_work_that_ends_the_transaction_itself_is_a_programming_error(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The work ended the transaction itself. Only the transaction runner commits.');

        $this->transactions->run(function (): void {
            $this->insert('a');
            $this->database->pdo()->commit();
        });
    }

    public function test_no_connection_is_opened_before_the_first_transaction(): void
    {
        $factory = new CountingConnectionFactory(new PdoConnectionFactory($this->settings()->database));
        $transactions = new PdoTransactionRunner(new LazyPdoDatabase($factory));

        self::assertSame(0, $factory->connections);

        $transactions->run(static fn (): null => null);

        self::assertSame(1, $factory->connections);
    }

    private function insert(string $key): void
    {
        (new Sql($this->database))->execute(
            'INSERT INTO rate_limits (`key`, window_start, hits) VALUES (?, ?, 1)',
            [$key, '2026-11-17 12:00:00'],
        );
    }

    /**
     * @return list<mixed>
     */
    private function keysSeenByAnotherSession(): array
    {
        return $this->sql()->column('SELECT `key` FROM rate_limits ORDER BY `key`');
    }
}
