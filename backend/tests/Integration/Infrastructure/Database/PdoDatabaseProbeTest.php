<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Infrastructure\Database;

use SmokeDetector\Infrastructure\Database\LazyPdoDatabase;
use SmokeDetector\Infrastructure\Database\PdoConnectionFactory;
use SmokeDetector\Infrastructure\Database\PdoDatabaseProbe;
use SmokeDetector\Tests\Support\Fake\RecordingLogger;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\SettingsFixture;

/**
 * The health check's question to the database: do you answer?
 */
final class PdoDatabaseProbeTest extends IntegrationTestCase
{
    public function test_a_database_that_answers_is_reachable(): void
    {
        $logger = new RecordingLogger();

        self::assertTrue((new PdoDatabaseProbe($this->newDatabase(), $logger))->isReachable());
        self::assertSame([], $logger->records);
    }

    public function test_a_database_that_cannot_be_connected_to_is_not_reachable_and_that_is_logged(): void
    {
        $logger = new RecordingLogger();
        $database = new LazyPdoDatabase(new PdoConnectionFactory(SettingsFixture::unreachableDatabase($this->settings())));

        self::assertFalse((new PdoDatabaseProbe($database, $logger))->isReachable());

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertStringStartsWith('The database cannot be reached: ', $logger->records[0]['message']);
        self::assertStringContainsString('SQLSTATE', $logger->records[0]['message']);
        self::assertStringNotContainsString($this->settings()->database->password->reveal(), $logger->text());
    }

    public function test_a_connection_that_was_lost_is_not_reachable(): void
    {
        $database = $this->newDatabase();
        $connectionId = $database->pdo()->query('SELECT CONNECTION_ID()');
        self::assertNotFalse($connectionId);
        // Another session ends this one, as a server restart or a timeout would.
        $this->sql()->execute('KILL ?', [$connectionId->fetchColumn()]);
        $logger = new RecordingLogger();

        self::assertFalse((new PdoDatabaseProbe($database, $logger))->isReachable());
        self::assertCount(1, $logger->records);
    }
}
