<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Privacy;

use RuntimeException;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * Looks at every row of every table of the test schema, whatever tables exist: for tests that must
 * hold for tables that are added later, too ("nothing about the user is left", "nothing changed").
 */
final class DatabaseContents
{
    /** The migration log holds no application data. */
    private const array IGNORED = ['phinxlog'];

    /**
     * @param list<string> $needles Texts that identify somebody: the user id, the email address, …
     *
     * @return array<string, int> Table => how many of its rows contain one of the needles in any
     *                            column. Tables without such a row are left out.
     */
    public static function rowsMentioning(Sql $sql, array $needles): array
    {
        $found = [];
        foreach (self::all($sql) as $table => $rows) {
            foreach ($rows as $row) {
                foreach ($row as $value) {
                    if (is_scalar($value) && self::contains((string) $value, $needles)) {
                        $found[$table] = ($found[$table] ?? 0) + 1;

                        break;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * @param list<string> $except Tables to leave out.
     *
     * @return array<string, list<array<string, mixed>>> Table => all of its rows, in a stable order.
     */
    public static function all(Sql $sql, array $except = []): array
    {
        $contents = [];
        foreach (self::tables($sql) as $table) {
            if (in_array($table, $except, true)) {
                continue;
            }
            // Table names cannot be bound. They come from information_schema and are checked in tables().
            $rows = $sql->rows("SELECT * FROM `{$table}`");
            usort($rows, static fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));
            $contents[$table] = $rows;
        }

        return $contents;
    }

    /**
     * @return list<string>
     */
    public static function tables(Sql $sql): array
    {
        $tables = [];
        $names = $sql->column(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' "
            . 'ORDER BY TABLE_NAME',
        );
        foreach ($names as $name) {
            if (!is_string($name) || preg_match('/\A[A-Za-z0-9_]+\z/', $name) !== 1) {
                throw new RuntimeException('Unexpected table name in the test schema.');
            }
            if (!in_array($name, self::IGNORED, true)) {
                $tables[] = $name;
            }
        }

        return $tables;
    }

    /**
     * @param list<string> $needles
     */
    private static function contains(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && stripos($value, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
