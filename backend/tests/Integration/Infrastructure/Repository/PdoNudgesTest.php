<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Infrastructure\Repository;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Nudge\Nudge;
use SmokeDetector\Application\Nudge\Nudges;
use SmokeDetector\Infrastructure\Database\Database;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoNudges;
use SmokeDetector\Infrastructure\Repository\PdoUserRepository;
use SmokeDetector\Tests\Support\Contract\NudgesContract;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\TestData;

/**
 * The nudges against the real database: the contract every implementation passes, and what only the
 * database can show (the columns, and that a nudge goes with either of its two accounts).
 */
final class PdoNudgesTest extends IntegrationTestCase
{
    use NudgesContract;

    private Database $database;

    protected function setUp(): void
    {
        // A connection of its own, as the application has: the test's own connection looks at the
        // rows from outside.
        $this->database = $this->newDatabase();
    }

    public function test_FR_SOC_03_a_nudge_is_stored_in_the_columns_of_the_design_in_utc(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));

        $this->nudges()->add(new Nudge(TestData::userId(1), TestData::userId(2), TestData::date('2026-11-18'), TestData::time('2026-11-17 23:10:05'), true));
        $this->nudges()->add(new Nudge(TestData::userId(2), TestData::userId(1), TestData::date('2026-11-17'), TestData::time('2026-11-17 23:11:00'), false));

        self::assertSame(
            [
                ['from_user_id' => TestData::uuid(1), 'to_user_id' => TestData::uuid(2), 'day' => '2026-11-18', 'created_at' => '2026-11-17 23:10:05', 'delivered' => 1],
                ['from_user_id' => TestData::uuid(2), 'to_user_id' => TestData::uuid(1), 'day' => '2026-11-17', 'created_at' => '2026-11-17 23:11:00', 'delivered' => 0],
            ],
            $this->sql()->rows('SELECT * FROM nudges ORDER BY created_at'),
        );
    }

    public function test_FR_ACC_08_a_nudge_goes_with_the_account_of_its_sender_and_of_its_receiver(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3));
        $this->nudges()->add(new Nudge(TestData::userId(1), TestData::userId(2), TestData::date('2026-11-17'), TestData::time('2026-11-17 12:00:00'), true));
        $this->nudges()->add(new Nudge(TestData::userId(2), TestData::userId(3), TestData::date('2026-11-17'), TestData::time('2026-11-17 12:00:00'), true));
        $this->nudges()->add(new Nudge(TestData::userId(3), TestData::userId(1), TestData::date('2026-11-17'), TestData::time('2026-11-17 12:00:00'), true));

        $this->sql()->execute('DELETE FROM users WHERE id = ?', [TestData::uuid(1)]);

        self::assertSame(
            [['from_user_id' => TestData::uuid(2), 'to_user_id' => TestData::uuid(3)]],
            $this->sql()->rows('SELECT from_user_id, to_user_id FROM nudges'),
        );
    }

    protected function nudges(): Nudges
    {
        return new PdoNudges(new Sql($this->database));
    }

    protected function givenUsers(UserId ...$ids): void
    {
        $users = new PdoUserRepository(new Sql($this->database));
        foreach ($ids as $id) {
            if ($users->find($id) === null) {
                $users->add(TestData::user((int) substr($id->value, -12)));
            }
        }
    }
}
