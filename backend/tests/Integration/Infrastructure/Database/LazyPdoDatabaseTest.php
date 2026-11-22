<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Infrastructure\Database;

use PDO;
use SmokeDetector\Config\DatabaseSettings;
use SmokeDetector\Infrastructure\Database\ConnectionFactory;
use SmokeDetector\Infrastructure\Database\DatabaseConnectionFailed;
use SmokeDetector\Infrastructure\Database\LazyPdoDatabase;
use SmokeDetector\Infrastructure\Database\PdoConnectionFactory;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Tests\Support\CountingConnectionFactory;
use SmokeDetector\Tests\Support\IntegrationTestCase;

/**
 * The one database connection of a request: opened when it is first needed, then shared.
 */
final class LazyPdoDatabaseTest extends IntegrationTestCase
{
    public function test_nothing_is_connected_until_the_connection_is_asked_for(): void
    {
        $factory = $this->countingFactory();

        $database = new LazyPdoDatabase($factory);

        // A request that is rejected before it reaches the database must not use one of the few
        // connections the hosting plan allows.
        self::assertSame(0, $factory->connections);

        $database->pdo();

        self::assertSame(1, $factory->connections);
    }

    public function test_the_same_connection_is_handed_out_every_time(): void
    {
        $factory = $this->countingFactory();
        $database = new LazyPdoDatabase($factory);

        $first = $database->pdo();
        $second = $database->pdo();

        self::assertSame($first, $second);
        self::assertSame(1, $factory->connections);
    }

    public function test_the_connection_comes_from_the_factory_and_is_therefore_strict_and_in_utc(): void
    {
        $database = new LazyPdoDatabase(new PdoConnectionFactory($this->settings()->database));

        $row = (new Sql($database))->row('SELECT @@SESSION.time_zone AS zone, @@SESSION.sql_mode AS mode');

        self::assertNotNull($row);
        self::assertSame('+00:00', $row['zone']);
        self::assertIsString($row['mode']);
        self::assertStringContainsString('STRICT_ALL_TABLES', $row['mode']);
    }

    public function test_a_failed_attempt_is_not_remembered(): void
    {
        $settings = $this->settings()->database;
        $unreachable = new DatabaseSettings($settings->host, 1, $settings->name, $settings->user, $settings->password);
        $factory = new class (new PdoConnectionFactory($unreachable), new PdoConnectionFactory($settings)) implements ConnectionFactory {
            public int $connections = 0;

            public function __construct(private ConnectionFactory $first, private ConnectionFactory $then)
            {
            }

            public function connect(): PDO
            {
                return $this->connections++ === 0 ? $this->first->connect() : $this->then->connect();
            }
        };
        $database = new LazyPdoDatabase($factory);

        try {
            $database->pdo();
            self::fail('The unreachable server accepted a connection.');
        } catch (DatabaseConnectionFailed) {
            // Expected: the first attempt goes to a port where nothing listens.
        }

        self::assertSame(1, (new Sql($database))->value('SELECT 1'));
    }

    private function countingFactory(): CountingConnectionFactory
    {
        return new CountingConnectionFactory(new PdoConnectionFactory($this->settings()->database));
    }
}
