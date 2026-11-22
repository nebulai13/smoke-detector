<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Infrastructure\Database;

use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Tests\Support\IntegrationTestCase;

/**
 * The small helper repositories use to run prepared statements.
 */
final class SqlTest extends IntegrationTestCase
{
    private Sql $sql;

    protected function setUp(): void
    {
        $this->sql = new Sql($this->newDatabase());
        $this->sql->execute(
            'CREATE TEMPORARY TABLE probe (id INT UNSIGNED NOT NULL PRIMARY KEY, name VARCHAR(40) NULL, '
            . 'active TINYINT(1) NOT NULL DEFAULT 0, ratio DECIMAL(4,1) NULL) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function test_execute_returns_the_number_of_changed_rows(): void
    {
        self::assertSame(1, $this->sql->execute('INSERT INTO probe (id, name) VALUES (?, ?)', [1, 'one']));
        self::assertSame(1, $this->sql->execute('INSERT INTO probe (id, name) VALUES (:id, :name)', ['id' => 2, 'name' => 'two']));
        self::assertSame(2, $this->sql->execute('UPDATE probe SET name = ? WHERE id < ?', ['x', 10]));
        self::assertSame(0, $this->sql->execute('DELETE FROM probe WHERE id = ?', [99]));
    }

    public function test_rows_come_back_as_lists_of_named_columns_with_their_types(): void
    {
        $this->sql->execute('INSERT INTO probe (id, name, active, ratio) VALUES (?, ?, ?, ?)', [1, 'one', true, 7.5]);
        $this->sql->execute('INSERT INTO probe (id, name, active, ratio) VALUES (?, ?, ?, ?)', [2, null, false, null]);

        self::assertSame(
            [
                ['id' => 1, 'name' => 'one', 'active' => 1, 'ratio' => '7.5'],
                ['id' => 2, 'name' => null, 'active' => 0, 'ratio' => null],
            ],
            $this->sql->rows('SELECT id, name, active, ratio FROM probe ORDER BY id'),
        );
        self::assertSame([], $this->sql->rows('SELECT id FROM probe WHERE id > ?', [10]));
    }

    public function test_row_is_the_first_row_or_null(): void
    {
        $this->sql->execute('INSERT INTO probe (id, name) VALUES (?, ?)', [1, 'one']);

        self::assertSame(['id' => 1, 'name' => 'one'], $this->sql->row('SELECT id, name FROM probe WHERE id = ?', [1]));
        self::assertNull($this->sql->row('SELECT id, name FROM probe WHERE id = ?', [2]));
    }

    public function test_value_is_the_first_column_of_the_first_row_or_null(): void
    {
        $this->sql->execute('INSERT INTO probe (id, name) VALUES (?, ?)', [1, 'one']);

        self::assertSame('one', $this->sql->value('SELECT name FROM probe WHERE id = ?', [1]));
        self::assertSame(1, $this->sql->value('SELECT COUNT(*) FROM probe'));
        self::assertNull($this->sql->value('SELECT name FROM probe WHERE id = ?', [2]));
    }

    public function test_column_is_the_first_column_of_every_row(): void
    {
        $this->sql->execute('INSERT INTO probe (id, name) VALUES (?, ?)', [2, 'two']);
        $this->sql->execute('INSERT INTO probe (id, name) VALUES (?, ?)', [1, 'one']);

        self::assertSame(['one', 'two'], $this->sql->column('SELECT name FROM probe ORDER BY id'));
        self::assertSame([], $this->sql->column('SELECT name FROM probe WHERE id > ?', [10]));
    }

    /**
     * @return iterable<string, array{bool|float|int|string|null, string, mixed}>
     */
    public static function parameters(): iterable
    {
        // With real prepared statements PDO would send false as '' (an error in strict mode) unless
        // each value is bound with its own type.
        yield 'false' => [false, 'active', 0];
        yield 'true' => [true, 'active', 1];
        yield 'integer' => [1, 'active', 1];
        yield 'text' => ['Zoë 🚭', 'name', 'Zoë 🚭'];
        yield 'empty text is not null' => ['', 'name', ''];
        yield 'null' => [null, 'name', null];
        yield 'float' => [12.5, 'ratio', '12.5'];
        yield 'decimal as text' => ['0.1', 'ratio', '0.1'];
    }

    #[DataProvider('parameters')]
    public function test_parameters_are_bound_with_their_own_type(bool|float|int|string|null $value, string $column, mixed $stored): void
    {
        // The column name comes from the data provider above, not from outside.
        $this->sql->execute("INSERT INTO probe (id, {$column}) VALUES (?, ?)", [1, $value]);

        self::assertSame($stored, $this->sql->value("SELECT {$column} FROM probe WHERE id = ?", [1]));
    }

    public function test_a_float_arrives_with_every_digit(): void
    {
        // PHP's own conversion to text keeps 14 digits ("precision"), which would store another number.
        foreach ([7.428571428571429, 0.1, 1 / 3, 20.0, 1.0e-7, 123456789.12345679, -2.5] as $float) {
            self::assertSame($float, $this->sql->value('SELECT CAST(? AS DOUBLE)', [$float]));
        }
    }

    public function test_a_value_is_never_part_of_the_statement(): void
    {
        $this->sql->execute('INSERT INTO probe (id, name) VALUES (?, ?)', [1, "x'); DROP TABLE probe; --"]);

        self::assertSame("x'); DROP TABLE probe; --", $this->sql->value('SELECT name FROM probe WHERE id = ?', [1]));
        self::assertNull($this->sql->value('SELECT id FROM probe WHERE name = ?', ["' OR '1'='1"]));
    }

    public function test_a_failing_statement_throws(): void
    {
        $this->sql->execute('INSERT INTO probe (id) VALUES (?)', [1]);

        try {
            $this->sql->execute('INSERT INTO probe (id) VALUES (?)', [1]);
            self::fail('The duplicate key was accepted.');
        } catch (PDOException $exception) {
            self::assertSame('23000', $exception->getCode());
        }
    }

    public function test_statements_run_on_the_shared_connection_and_therefore_in_its_transaction(): void
    {
        $database = $this->newDatabase();
        $sql = new Sql($database);
        $sql->execute('CREATE TEMPORARY TABLE shared_probe (id INT NOT NULL) ENGINE=InnoDB');

        $database->pdo()->beginTransaction();
        $sql->execute('INSERT INTO shared_probe (id) VALUES (?)', [1]);
        $database->pdo()->rollBack();

        self::assertSame(0, $sql->value('SELECT COUNT(*) FROM shared_probe'));
    }
}
