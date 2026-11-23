<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Migrations;

use PHPUnit\Framework\TestCase;
use SmokeDetector\Tests\Support\Paths;

/**
 * Rules for the migration files themselves (the schema they produce is checked by the integration
 * test SchemaConventionsTest).
 *
 * The deploy runs the migrations while the previous release is still serving requests (HLD §8), so a
 * migration may only add to the schema. Taking something away needs its own, later release, after
 * no deployed code uses it any more.
 */
final class MigrationConventionsTest extends TestCase
{
    public function test_there_is_at_least_one_migration(): void
    {
        self::assertNotSame([], self::files());
    }

    public function test_file_names_are_a_timestamp_and_a_snake_case_name(): void
    {
        foreach (self::files() as $name => $source) {
            self::assertMatchesRegularExpression('/\A\d{14}_[a-z0-9]+(_[a-z0-9]+)*\.php\z/', $name);
            self::assertStringContainsString('declare(strict_types=1);', $source, $name);
        }
    }

    public function test_migrations_only_add_to_the_schema(): void
    {
        $destructive = [
            '/\bDROP\s+(TABLE|COLUMN|INDEX|KEY|DATABASE|SCHEMA|FOREIGN|PRIMARY|CONSTRAINT)\b/i' => 'drops something',
            '/\bRENAME\b/i' => 'renames something',
            '/\bTRUNCATE\b/i' => 'empties a table',
            '/\bDELETE\s+FROM\b/i' => 'deletes rows',
            '/\b(MODIFY|CHANGE)\s+(COLUMN\b|`)/i' => 'changes a column in place',
            '/->\s*(drop|dropTable|removeColumn|renameColumn|changeColumn|removeIndex|removeIndexByName|dropForeignKey|rename)\s*\(/'
                => 'uses a destructive table method',
        ];

        foreach (self::files() as $name => $source) {
            foreach ($destructive as $pattern => $what) {
                self::assertDoesNotMatchRegularExpression(
                    $pattern,
                    $source,
                    "{$name} {$what}. The previous release is still running when migrations are applied, "
                    . 'so a migration may only add tables, columns and indexes.',
                );
            }
        }
    }

    public function test_migrations_cannot_be_rolled_back(): void
    {
        // A rollback is a destructive change by another name. A bad release is fixed by rolling
        // forward: the code goes back to the previous release, which still works with the larger schema.
        foreach (self::files() as $name => $source) {
            self::assertDoesNotMatchRegularExpression('/function\s+(down|change)\s*\(/', $source, $name);
            self::assertMatchesRegularExpression('/function\s+up\s*\(\s*\)\s*:\s*void/', $source, $name);
        }
    }

    public function test_new_tables_declare_engine_and_character_set_and_tolerate_a_second_run(): void
    {
        foreach (self::files() as $name => $source) {
            $creates = preg_match_all('/\bCREATE\s+TABLE\b/i', $source);
            self::assertSame($creates, preg_match_all('/\bCREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\b/i', $source), $name);
            self::assertSame(
                $creates,
                preg_match_all('/ENGINE=InnoDB\s+DEFAULT\s+CHARSET=utf8mb4\s+COLLATE=utf8mb4_unicode_ci/', $source),
                "{$name}: every CREATE TABLE must end with ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci.",
            );
            // The schema is built with reviewable SQL, not with the table builder and its defaults.
            self::assertDoesNotMatchRegularExpression('/->\s*table\s*\(/', $source, $name);
        }
    }

    /**
     * @return array<string, string> File name => source.
     */
    private static function files(): array
    {
        $files = [];
        foreach (glob(Paths::migrations() . '/*.php') ?: [] as $file) {
            $files[basename($file)] = (string) file_get_contents($file);
        }

        return $files;
    }
}
