<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Infrastructure\Repository;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Competition\Duels;
use SmokeDetector\Application\Competition\DuelStatus;
use SmokeDetector\Application\Competition\DuelWinner;
use SmokeDetector\Application\Competition\SideResult;
use SmokeDetector\Application\Day\ProgressRecords;
use SmokeDetector\Infrastructure\Database\Database;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoDuels;
use SmokeDetector\Infrastructure\Repository\PdoProgressRecords;
use SmokeDetector\Infrastructure\Repository\PdoUserRepository;
use SmokeDetector\Tests\Support\Contract\DuelRepositoryContract;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\TestData;

/**
 * The duel repository against the real database: the contract every implementation passes, and
 * what only the database can show (the columns, and what is left of a duel when an account goes).
 */
final class PdoDuelRepositoryTest extends IntegrationTestCase
{
    use DuelRepositoryContract;

    private Database $database;

    protected function setUp(): void
    {
        // A connection of its own, as the application has: the test's own connection looks at the
        // rows from outside.
        $this->database = $this->newDatabase();
    }

    public function test_FR_COMP_01_a_proposal_is_stored_in_the_columns_of_the_design_in_utc(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));

        $this->duels()->save(TestData::duel(10, 2, 1, 90, createdAt: '2026-11-17 12:00:01'));

        $row = $this->sql()->row('SELECT * FROM duels');
        self::assertIsArray($row);
        self::assertSame(
            [
                'id' => TestData::uuid(10), 'challenger_id' => TestData::uuid(2), 'opponent_id' => TestData::uuid(1), 'period_days' => 90,
                'status' => 'proposed', 'created_at' => '2026-11-17 12:00:01', 'expires_at' => '2026-11-20 12:00:01', 'answered_at' => null,
                'challenger_start' => null, 'opponent_start' => null, 'decided_at' => null, 'winner' => null,
            ],
            array_slice($row, 0, 12, true),
        );
        // Everything of a result is NULL until the duel is decided.
        self::assertSame(array_fill(0, 13, null), array_values(array_slice($row, 12)));
    }

    public function test_FR_COMP_04_a_decided_duel_is_stored_with_both_sides_as_they_were_scored(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));

        $this->duels()->save(TestData::duel(
            10, 1, 2, 7, DuelStatus::Completed, '2026-11-01 08:00:00', '2026-11-02 09:00:00', '2026-11-03', '2026-11-04',
            TestData::decision(DuelWinner::Opponent, new SideResult(120, 630, 840, 1, 7, 20), new SideResult(95, 0, 665, 7, 6, 100), '2026-11-12 23:00:40', 'v1'),
        ));

        self::assertSame(
            [
                'id' => TestData::uuid(10), 'challenger_id' => TestData::uuid(1), 'opponent_id' => TestData::uuid(2), 'period_days' => 7,
                'status' => 'completed', 'created_at' => '2026-11-01 08:00:00', 'expires_at' => '2026-11-04 08:00:00', 'answered_at' => '2026-11-02 09:00:00',
                'challenger_start' => '2026-11-03', 'opponent_start' => '2026-11-04', 'decided_at' => '2026-11-12 23:00:40', 'winner' => 'opponent',
                'challenger_b0_tenths' => 120, 'challenger_actual_tenths' => 630, 'challenger_expected_tenths' => 840,
                'challenger_smoke_free_days' => 1, 'challenger_check_ins' => 7, 'challenger_xp' => 20,
                'opponent_b0_tenths' => 95, 'opponent_actual_tenths' => 0, 'opponent_expected_tenths' => 665,
                'opponent_smoke_free_days' => 7, 'opponent_check_ins' => 6, 'opponent_xp' => 100,
                'rules_version' => 'v1',
            ],
            $this->sql()->row('SELECT * FROM duels'),
        );
    }

    public function test_FR_ACC_08_a_duel_does_not_point_at_an_account_that_is_gone(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->duels()->save(TestData::duel(10, 1, 2));
        $this->duels()->save(TestData::duel(11, 2, 1));

        // The safety net under the eraser: the foreign keys take the id out. The eraser does the rest
        // (it deletes proposals and removes the scores of the side that is gone).
        $this->sql()->execute('DELETE FROM users WHERE id = ?', [TestData::uuid(1)]);

        self::assertSame(
            [
                ['id' => TestData::uuid(10), 'challenger_id' => null, 'opponent_id' => TestData::uuid(2)],
                ['id' => TestData::uuid(11), 'challenger_id' => TestData::uuid(2), 'opponent_id' => null],
            ],
            $this->sql()->rows('SELECT id, challenger_id, opponent_id FROM duels ORDER BY id'),
        );
    }

    protected function duels(): Duels
    {
        return new PdoDuels(new Sql($this->database));
    }

    protected function progressRecords(): ProgressRecords
    {
        return new PdoProgressRecords(new Sql($this->database));
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
