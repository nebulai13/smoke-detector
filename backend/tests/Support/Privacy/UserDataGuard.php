<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Privacy;

use SmokeDetector\Infrastructure\Database\Sql;

/**
 * Compares the tables of a database schema with the user data registry, so that no table with
 * data of a user can be forgotten by the data export (FR-ACC-07) or by account deletion (FR-ACC-08).
 *
 * Every table must be accounted for: either it is registered with an exporter and an eraser, or it
 * is on an exclusion list that says why it needs neither. A table that is in neither list is
 * reported, and the report says what points at users in it: a foreign key to `users` (directly or
 * through other tables) or a column named like a user reference. A table without any such hint is
 * reported too: whoever adds a table decides, in writing, which of the two lists it belongs to.
 */
final class UserDataGuard
{
    /** The account itself. It is exported as the profile and deleted last (see UserDataRegistry). */
    private const string ACCOUNTS = 'users';

    /**
     * @param list<string>          $registered The tables of the user data registry.
     * @param array<string, string> $excluded   Table => why it holds nothing to export or erase.
     *
     * @return list<string> One sentence per problem. Empty when every table is accounted for.
     */
    public static function violations(Sql $sql, array $registered, array $excluded): array
    {
        $tables = self::tables($sql);
        $references = self::referencesToUsers($sql);
        $columns = self::userColumns($sql);

        $violations = [];
        foreach ($tables as $table) {
            if ($table === self::ACCOUNTS) {
                continue;
            }
            $isRegistered = in_array($table, $registered, true);
            $isExcluded = array_key_exists($table, $excluded);
            if ($isRegistered && $isExcluded) {
                $violations[] = "Table `{$table}` is registered for export and erasure and excluded at the same time.";
            }
            if ($isRegistered || $isExcluded) {
                continue;
            }

            $hints = array_merge(
                isset($references[$table]) ? ["it refers to users through the foreign key {$references[$table]}"] : [],
                isset($columns[$table]) ? ['it has the column ' . implode(', ', $columns[$table])] : [],
            );
            $violations[] = "Table `{$table}` is neither registered in the user data registry nor on the exclusion list"
                . ($hints === [] ? '. Decide whether it holds data of a user.' : ', and ' . implode(' and ', $hints) . '.');
        }

        foreach ($registered as $table) {
            if (!in_array($table, $tables, true)) {
                $violations[] = "Table `{$table}` is registered in the user data registry but does not exist.";
            }
        }
        foreach (array_keys($excluded) as $table) {
            if (!in_array($table, $tables, true)) {
                $violations[] = "Table `{$table}` is on the exclusion list but does not exist.";
            }
            if (trim($excluded[$table]) === '') {
                $violations[] = "Table `{$table}` is on the exclusion list without a reason.";
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private static function tables(Sql $sql): array
    {
        $tables = [];
        $names = $sql->column(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' "
            . 'ORDER BY TABLE_NAME',
        );
        foreach ($names as $name) {
            if (is_string($name)) {
                $tables[] = $name;
            }
        }

        return $tables;
    }

    /**
     * Every table from which a chain of foreign keys leads to `users`.
     *
     * @return array<string, string> Table => the chain, e.g. "reactions.event_id → feed_events.user_id → users".
     */
    private static function referencesToUsers(Sql $sql): array
    {
        $keys = $sql->rows(
            'SELECT TABLE_NAME AS child, COLUMN_NAME AS col, REFERENCED_TABLE_NAME AS parent '
            . 'FROM information_schema.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL '
            . 'ORDER BY TABLE_NAME, COLUMN_NAME',
        );

        // Walks outwards from `users` until no further table is reached.
        $chains = [self::ACCOUNTS => self::ACCOUNTS];
        do {
            $found = false;
            foreach ($keys as $key) {
                $child = $key['child'];
                $parent = $key['parent'];
                if (!is_string($child) || !is_string($parent) || !is_string($key['col'])) {
                    continue;
                }
                if (isset($chains[$parent]) && !isset($chains[$child])) {
                    $chains[$child] = "{$child}.{$key['col']} → {$chains[$parent]}";
                    $found = true;
                }
            }
        } while ($found);
        unset($chains[self::ACCOUNTS]);

        return $chains;
    }

    /**
     * Columns whose name says "user": user_id, user_a, from_user, …
     *
     * @return array<string, list<string>> Table => such columns.
     */
    private static function userColumns(Sql $sql): array
    {
        $columns = [];
        $rows = $sql->rows(
            'SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION',
        );
        foreach ($rows as $row) {
            if (is_string($row['tbl']) && is_string($row['col']) && preg_match('/(?:^|_)users?(?:_|$)/i', $row['col']) === 1) {
                $columns[$row['tbl']][] = $row['col'];
            }
        }

        return $columns;
    }
}
