<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Infrastructure\Repository;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Badge\BadgeRecords;
use SmokeDetector\Domain\Badge\Badge;
use SmokeDetector\Infrastructure\Database\Database;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoBadgeRecords;
use SmokeDetector\Infrastructure\Repository\PdoUserRepository;
use SmokeDetector\Tests\Support\Contract\BadgeRecordsContract;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\TestData;

/**
 * The badge records against the real database: the contract every implementation passes, and what
 * only the database can show (the columns, and that no row outlives its account).
 */
final class PdoBadgeRecordsTest extends IntegrationTestCase
{
    use BadgeRecordsContract;

    private Database $database;

    protected function setUp(): void
    {
        // A connection of its own, as the application has: the test's own connection looks at the
        // rows from outside.
        $this->database = $this->newDatabase();
    }

    public function test_FR_GAME_08_a_badge_is_stored_in_the_columns_of_the_design_in_utc(): void
    {
        $this->givenUsers(TestData::userId(1));

        $this->badgeRecords()->add(TestData::badge(1, Badge::LeagueChampionHalfYear, '2026-07-03 22:00:41'));

        self::assertSame(
            [['user_id' => TestData::uuid(1), 'badge_key' => 'league_champion_half_year', 'awarded_at' => '2026-07-03 22:00:41']],
            $this->sql()->rows('SELECT * FROM user_badges'),
        );
    }

    public function test_FR_ACC_08_no_badge_outlives_its_account(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $this->badgeRecords()->add(TestData::badge(1, Badge::FirstCheckIn));
        $this->badgeRecords()->add(TestData::badge(2, Badge::FirstCheckIn));

        $this->sql()->execute('DELETE FROM users WHERE id = ?', [TestData::uuid(1)]);

        self::assertSame([TestData::uuid(2)], $this->sql()->column('SELECT user_id FROM user_badges'));
    }

    protected function badgeRecords(): BadgeRecords
    {
        return new PdoBadgeRecords(new Sql($this->database));
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
