<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Infrastructure\Database;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use SmokeDetector\Config\DatabaseSettings;
use SmokeDetector\Config\Secret;
use SmokeDetector\Infrastructure\Database\DatabaseConnectionFailed;
use SmokeDetector\Infrastructure\Database\PdoConnectionFactory;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Tests\Support\ExistingConnection;
use SmokeDetector\Tests\Support\IntegrationTestCase;

/**
 * Every connection runs in UTC, in strict mode and with utf8mb4, whatever the server defaults are.
 *
 * The hosting server defaults to a non-strict sql_mode, utf8mb3 and CET (HLD §11), which silently
 * truncates text, breaks emoji and shifts timestamps. The test server is given the same defaults
 * (TestDatabase), and the first test proves that they really are unsafe here.
 */
final class PdoConnectionFactoryTest extends IntegrationTestCase
{
    public function test_control_a_connection_without_the_factory_gets_the_unsafe_server_defaults(): void
    {
        $database = $this->settings()->database;
        $raw = new Sql(new ExistingConnection(new PDO(
            "mysql:host={$database->host};port={$database->port};dbname={$database->name}",
            $database->user,
            $database->password->reveal(),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        )));

        self::assertSame('', $raw->value('SELECT @@SESSION.sql_mode'));
        self::assertSame('+01:00', $raw->value('SELECT @@SESSION.time_zone'));
        self::assertNotSame($raw->value('SELECT NOW()'), $raw->value('SELECT UTC_TIMESTAMP()'));

        // Not strict: the text is cut to five characters with a mere warning.
        $raw->execute('CREATE TEMPORARY TABLE probe (v VARCHAR(5) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $raw->execute("INSERT INTO probe (v) VALUES ('123456789')");
        self::assertSame('12345', $raw->value('SELECT v FROM probe'));
    }

    public function test_a_too_long_string_is_rejected_instead_of_truncated(): void
    {
        $sql = $this->connect();
        $sql->execute('CREATE TEMPORARY TABLE probe (v VARCHAR(5) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $sql->execute('INSERT INTO probe (v) VALUES (?)', ['12345']);

        try {
            $sql->execute('INSERT INTO probe (v) VALUES (?)', ['123456']);
            self::fail('A string longer than the column was accepted.');
        } catch (PDOException $exception) {
            self::assertSame('22001', $exception->getCode());
        }
        self::assertSame(['12345'], $sql->column('SELECT v FROM probe'));
    }

    public function test_an_emoji_round_trips(): void
    {
        $sql = $this->connect();
        $sql->execute(
            'CREATE TEMPORARY TABLE probe (v VARCHAR(20) NOT NULL) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $text = 'Löwe 🚭🔥 ok';

        $sql->execute('INSERT INTO probe (v) VALUES (?)', [$text]);

        self::assertSame($text, $sql->value('SELECT v FROM probe'));
        // Four-byte characters arrive as one character each, not as "????" or as mojibake.
        self::assertSame(10, $sql->value('SELECT CHAR_LENGTH(v) FROM probe'));
        self::assertSame('F09F9AAD', $sql->value('SELECT HEX(SUBSTRING(v, 6, 1)) FROM probe'));
        self::assertSame('🚭', $sql->value('SELECT ?', ['🚭']));
    }

    public function test_now_equals_utc_timestamp(): void
    {
        $sql = $this->connect();

        self::assertSame('+00:00', $sql->value('SELECT @@SESSION.time_zone'));

        $row = $sql->row('SELECT NOW(6) AS now, UTC_TIMESTAMP(6) AS utc, UNIX_TIMESTAMP() AS unix');
        self::assertNotNull($row);
        self::assertSame($row['utc'], $row['now']);
        self::assertIsString($row['now']);
        self::assertIsInt($row['unix']);
        // And the server's UTC is PHP's UTC (with some slack, in case the two clocks differ slightly).
        self::assertEqualsWithDelta($row['unix'], strtotime($row['now'] . ' UTC'), 1);
        self::assertEqualsWithDelta(time(), $row['unix'], 60);
    }

    public function test_the_session_sql_mode_is_strict(): void
    {
        $mode = $this->connect()->value('SELECT @@SESSION.sql_mode');

        self::assertIsString($mode);
        self::assertEqualsCanonicalizing(
            [
                'STRICT_ALL_TABLES',
                'STRICT_TRANS_TABLES',
                'ERROR_FOR_DIVISION_BY_ZERO',
                'NO_ZERO_DATE',
                'NO_ZERO_IN_DATE',
                'NO_ENGINE_SUBSTITUTION',
                'ONLY_FULL_GROUP_BY',
            ],
            explode(',', $mode),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valuesThatAreNotData(): iterable
    {
        yield 'a date that does not exist' => ["INSERT INTO probe (d) VALUES ('2026-02-30')", '22007'];
        yield 'a zero date' => ["INSERT INTO probe (d) VALUES ('0000-00-00')", '22007'];
        yield 'a number out of range' => ['INSERT INTO probe (n) VALUES (300)', '22003'];
        yield 'text for a number' => ["INSERT INTO probe (n) VALUES ('abc')", '22007'];
        yield 'a division by zero' => ['INSERT INTO probe (n) VALUES (1 / 0)', '22012'];
    }

    #[DataProvider('valuesThatAreNotData')]
    public function test_strict_mode_rejects_values_the_server_would_otherwise_bend(string $statement, string $sqlState): void
    {
        $sql = $this->connect();
        $sql->execute(
            "CREATE TEMPORARY TABLE probe (d DATE NOT NULL DEFAULT '2026-01-01', n TINYINT UNSIGNED NULL) ENGINE=InnoDB",
        );

        try {
            $sql->execute($statement);
            self::fail('The statement was accepted.');
        } catch (PDOException $exception) {
            self::assertSame($sqlState, $exception->getCode());
        }
        self::assertSame(0, $sql->value('SELECT COUNT(*) FROM probe'));
    }

    public function test_a_query_that_picks_arbitrary_rows_is_rejected(): void
    {
        $sql = $this->connect();
        $sql->execute('CREATE TEMPORARY TABLE probe (a INT NOT NULL, b INT NOT NULL) ENGINE=InnoDB');

        // ONLY_FULL_GROUP_BY: without it the server returns the b of whichever row it saw first.
        $this->expectException(PDOException::class);

        $sql->rows('SELECT a, b FROM probe GROUP BY a');
    }

    public function test_the_connection_talks_utf8mb4_with_the_collation_of_the_tables(): void
    {
        $row = $this->connect()->row(
            'SELECT @@SESSION.character_set_client AS client, @@SESSION.character_set_connection AS connection, '
            . '@@SESSION.character_set_results AS results, @@SESSION.collation_connection AS collation',
        );

        self::assertSame(
            ['client' => 'utf8mb4', 'connection' => 'utf8mb4', 'results' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
            $row,
        );
    }

    public function test_errors_are_exceptions_and_values_keep_their_types(): void
    {
        $pdo = (new PdoConnectionFactory($this->settings()->database))->connect();
        $sql = new Sql(new ExistingConnection($pdo));

        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        self::assertSame(PDO::FETCH_ASSOC, $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
        // Real prepared statements (not emulated ones): the value never becomes part of the SQL text,
        // and numbers come back as numbers.
        self::assertFalse($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
        self::assertFalse($pdo->getAttribute(PDO::ATTR_PERSISTENT));
        self::assertSame(['i' => 7, 't' => 'seven', 'n' => null], $sql->row("SELECT 7 AS i, 'seven' AS t, NULL AS n"));

        $this->expectException(PDOException::class);
        $pdo->query('SELECT * FROM a_table_that_does_not_exist');
    }

    public function test_a_statement_cannot_smuggle_in_a_second_one(): void
    {
        $pdo = (new PdoConnectionFactory($this->settings()->database))->connect();
        $sql = new Sql(new ExistingConnection($pdo));
        $sql->execute('CREATE TEMPORARY TABLE probe (v VARCHAR(5) NOT NULL) ENGINE=InnoDB');

        try {
            $pdo->exec("INSERT INTO probe (v) VALUES ('a'); INSERT INTO probe (v) VALUES ('b')");
            self::fail('Two statements were accepted in one call.');
        } catch (PDOException $exception) {
            self::assertSame('42000', $exception->getCode());
        }
        self::assertSame(0, $sql->value('SELECT COUNT(*) FROM probe'));
    }

    public function test_every_call_opens_a_new_connection(): void
    {
        $first = $this->connect()->value('SELECT CONNECTION_ID()');
        $second = $this->connect()->value('SELECT CONNECTION_ID()');

        self::assertIsInt($first);
        self::assertIsInt($second);
        self::assertNotSame($first, $second);
    }

    public function test_a_wrong_password_fails_without_showing_it(): void
    {
        $database = $this->settings()->database;
        $factory = new PdoConnectionFactory(new DatabaseSettings(
            $database->host,
            $database->port,
            $database->name,
            $database->user,
            new Secret('LEAK-wrong-password'),
        ));

        try {
            $factory->connect();
            self::fail('A wrong password was accepted.');
        } catch (DatabaseConnectionFailed $exception) {
            self::assertSame('Cannot connect to the database.', $exception->getMessage());
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
            // Message and stack trace of both exceptions.
            $text = (string) $exception;
            self::assertStringContainsString('PDOException', $text);
            self::assertStringNotContainsString('LEAK', $text);
            self::assertStringNotContainsString($database->password->reveal(), $text);
        }
    }

    public function test_an_unreachable_server_fails_with_the_same_exception(): void
    {
        $database = $this->settings()->database;
        // Port 1 on the database host: nothing listens there, so the attempt is refused at once.
        $factory = new PdoConnectionFactory(new DatabaseSettings(
            $database->host,
            1,
            $database->name,
            $database->user,
            $database->password,
        ));

        $this->expectException(DatabaseConnectionFailed::class);
        $this->expectExceptionMessage('Cannot connect to the database.');

        $factory->connect();
    }

    /**
     * A new connection from the factory under test.
     */
    private function connect(): Sql
    {
        return new Sql(new ExistingConnection((new PdoConnectionFactory($this->settings()->database))->connect()));
    }
}
