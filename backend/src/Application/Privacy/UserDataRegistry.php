<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Privacy;

use InvalidArgumentException;
use SmokeDetector\Application\Account\UserId;

/**
 * Every table that holds data of a user, each with its exporter and its eraser (HLD §4.5
 * "Account"). The data export and account deletion go through this list and nothing else, so a
 * table that is in it cannot be forgotten by either.
 *
 * That no table is missing from the list is checked against the database schema by a test
 * (UserDataGuardTest): a new table that refers to users fails the build until it is registered.
 *
 * The account itself (the `users` row) is not in the list: it is exported as the profile and
 * deleted last, after every eraser has run.
 */
final readonly class UserDataRegistry
{
    /** @var list<UserDataTable> */
    private array $tables;

    /**
     * @param list<UserDataTable> $tables In the order their erasers run. A table whose rows are
     *                                    referred to by another registered table comes after it.
     */
    public function __construct(array $tables)
    {
        $names = [];
        $members = [];
        foreach ($tables as $table) {
            if ($table->table === 'users') {
                throw new InvalidArgumentException('The table "users" is the account itself and cannot be registered.');
            }
            if (in_array($table->table, $names, true)) {
                throw new InvalidArgumentException("The table \"{$table->table}\" is registered twice.");
            }
            foreach (array_keys($table->exporters()) as $member) {
                if (in_array($member, $members, true)) {
                    throw new InvalidArgumentException("The export member \"{$member}\" is used twice.");
                }
                $members[] = $member;
            }
            $names[] = $table->table;
        }

        $this->tables = $tables;
    }

    /**
     * @return list<string> The names of the registered database tables.
     */
    public function tables(): array
    {
        return array_map(static fn (UserDataTable $table): string => $table->table, $this->tables);
    }

    /**
     * @return array<string, array<array-key, mixed>> Export member => what its table holds about the
     *                                                user. A table with nothing to export as one
     *                                                object has no member.
     */
    public function export(UserId $userId): array
    {
        $export = [];
        foreach ($this->tables as $table) {
            foreach ($table->exporters() as $member => $exporter) {
                $data = $exporter->export($userId);
                if ($data !== null) {
                    $export[$member] = $data;
                }
            }
        }

        return $export;
    }

    /**
     * Runs every eraser. The caller holds the transaction and deletes the account afterwards.
     */
    public function erase(UserId $userId): void
    {
        foreach ($this->tables as $table) {
            $table->eraser->erase($userId);
        }
    }
}
