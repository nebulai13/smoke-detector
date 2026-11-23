<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Infrastructure\Repository;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Competition\LeagueResults;
use SmokeDetector\Domain\Xp\LeaguePeriod;
use SmokeDetector\Infrastructure\Database\Database;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoLeagueResults;
use SmokeDetector\Infrastructure\Repository\PdoUserRepository;
use SmokeDetector\Tests\Support\Contract\LeagueResultsContract;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\TestData;

/**
 * The league results against the real database: the contract every implementation passes, and what
 * only the database can show (the columns, and that no row outlives its account).
 */
final class PdoLeagueResultsTest extends IntegrationTestCase
{
    use LeagueResultsContract;

    private Database $database;

    protected function setUp(): void
    {
        // A connection of its own, as the application has: the test's own connection looks at the
        // rows from outside.
        $this->database = $this->newDatabase();
    }

    public function test_FR_COMP_06_a_result_is_stored_in_the_columns_of_the_design_in_utc(): void
    {
        $this->givenUsers(TestData::userId(1));

        $this->leagueResults()->save(TestData::leagueResult(1, LeaguePeriod::HalfYear, '2026-H1', 805, 145600, 145705, 3, 180, 181, '2026-07-02 22:00:41', '2026-07-02 22:15:41'));
        $this->leagueResults()->save(TestData::leagueResult(1, LeaguePeriod::Week, '2026-W46', award: [2, 4, 100], rankedAt: '2026-11-18 23:01:05'));

        self::assertSame(
            [
                [
                    'user_id' => TestData::uuid(1), 'period_type' => 'half_year', 'period_key' => '2026-H1', 'b0_tenths' => 805, 'actual_tenths' => 145600,
                    'expected_tenths' => 145705, 'smoke_free_days' => 3, 'check_ins' => 180, 'counted_days' => 181, 'rules_version' => 'v1',
                    'scored_at' => '2026-07-02 22:00:41', 'check_at' => '2026-07-02 22:15:41', 'rank' => null, 'participants' => null, 'xp' => null, 'ranked_at' => null,
                ],
                [
                    'user_id' => TestData::uuid(1), 'period_type' => 'week', 'period_key' => '2026-W46', 'b0_tenths' => 120, 'actual_tenths' => 420,
                    'expected_tenths' => 840, 'smoke_free_days' => 0, 'check_ins' => 7, 'counted_days' => 7, 'rules_version' => 'v1',
                    'scored_at' => '2026-11-17 23:00:20', 'check_at' => '2026-11-17 23:00:20', 'rank' => 2, 'participants' => 4, 'xp' => 100, 'ranked_at' => '2026-11-18 23:01:05',
                ],
            ],
            $this->sql()->rows('SELECT * FROM league_results ORDER BY scored_at'),
        );
    }

    public function test_FR_ACC_08_no_result_outlives_its_account(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->leagueResults()->save(TestData::leagueResult(1));
        $this->leagueResults()->save(TestData::leagueResult(2));

        $this->sql()->execute('DELETE FROM users WHERE id = ?', [TestData::uuid(1)]);

        self::assertSame([TestData::uuid(2)], $this->sql()->column('SELECT user_id FROM league_results'));
    }

    protected function leagueResults(): LeagueResults
    {
        return new PdoLeagueResults(new Sql($this->database));
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
