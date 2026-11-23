<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Infrastructure\Repository;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Friends\Blocks;
use SmokeDetector\Application\Friends\FriendLinks;
use SmokeDetector\Application\Friends\InviteCode;
use SmokeDetector\Application\Friends\InviteCodes;
use SmokeDetector\Infrastructure\Database\Database;
use SmokeDetector\Infrastructure\Database\Sql;
use SmokeDetector\Infrastructure\Repository\PdoBlocks;
use SmokeDetector\Infrastructure\Repository\PdoFriendLinks;
use SmokeDetector\Infrastructure\Repository\PdoInviteCodes;
use SmokeDetector\Infrastructure\Repository\PdoUserRepository;
use SmokeDetector\Tests\Support\Contract\FriendRepositoriesContract;
use SmokeDetector\Tests\Support\IntegrationTestCase;
use SmokeDetector\Tests\Support\TestData;

/**
 * The repositories of the friends against the real database: the contract every implementation
 * passes, and what only the database can show (the columns, and that no row outlives an account).
 */
final class PdoFriendRepositoriesTest extends IntegrationTestCase
{
    use FriendRepositoriesContract;

    private Database $database;

    protected function setUp(): void
    {
        // A connection of its own, as the application has: the test's own connection looks at the
        // rows from outside.
        $this->database = $this->newDatabase();
    }

    public function test_FR_FRND_01_an_invite_code_is_stored_in_the_columns_of_the_design_in_utc(): void
    {
        $this->givenUsers(TestData::userId(1));

        $this->inviteCodes()->assign(TestData::userId(1), InviteCode::fromInput('k7m2qx9rtb'), TestData::time('2026-11-17 12:00:00'));
        $this->inviteCodes()->assign(TestData::userId(1), InviteCode::fromInput('ZZZZZZZZZZ'), TestData::time('2026-11-18 13:30:05'));

        // One row per user, and the code in capitals.
        self::assertSame(
            [['user_id' => TestData::uuid(1), 'code' => 'ZZZZZZZZZZ', 'created_at' => '2026-11-18 13:30:05']],
            $this->sql()->rows('SELECT * FROM invite_codes'),
        );
    }

    public function test_FR_FRND_01_a_link_is_stored_once_with_the_smaller_id_first_whoever_asked(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3));

        $this->friendLinks()->save(TestData::friendship(2, 1, '2026-11-15 10:00:00'));
        $this->friendLinks()->save(TestData::friendship(2, 3, '2026-11-15 11:00:00', '2026-11-16 08:30:00'));

        self::assertSame(
            [
                ['user_a' => TestData::uuid(1), 'user_b' => TestData::uuid(2), 'requested_by' => TestData::uuid(2), 'requested_at' => '2026-11-15 10:00:00', 'accepted_at' => null],
                ['user_a' => TestData::uuid(2), 'user_b' => TestData::uuid(3), 'requested_by' => TestData::uuid(2), 'requested_at' => '2026-11-15 11:00:00', 'accepted_at' => '2026-11-16 08:30:00'],
            ],
            $this->sql()->rows('SELECT * FROM friendships ORDER BY requested_at'),
        );
    }

    public function test_FR_FRND_02_a_block_is_stored_in_the_columns_of_the_design(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));

        $this->blocks()->add(TestData::block(2, 1, '2026-11-16 09:00:00'));

        self::assertSame(
            [['blocker_id' => TestData::uuid(2), 'blocked_id' => TestData::uuid(1), 'created_at' => '2026-11-16 09:00:00']],
            $this->sql()->rows('SELECT * FROM blocks'),
        );
    }

    public function test_FR_ACC_08_no_code_link_or_block_outlives_one_of_its_accounts(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2), TestData::userId(3));
        $this->inviteCodes()->assign(TestData::userId(1), InviteCode::fromInput('K7M2QX9RTB'), TestData::time('2026-11-17 12:00:00'));
        $this->inviteCodes()->assign(TestData::userId(2), InviteCode::fromInput('K7M2QX9RTC'), TestData::time('2026-11-17 12:00:00'));
        $this->friendLinks()->save(TestData::friendship(1, 2));
        $this->friendLinks()->save(TestData::friendship(3, 1));
        $this->friendLinks()->save(TestData::friendship(2, 3));
        $this->blocks()->add(TestData::block(1, 2));
        $this->blocks()->add(TestData::block(3, 1));
        $this->blocks()->add(TestData::block(2, 3));

        // The safety net under the erasers: the foreign keys.
        $this->sql()->execute('DELETE FROM users WHERE id = ?', [TestData::uuid(1)]);

        self::assertSame([TestData::uuid(2)], $this->sql()->column('SELECT user_id FROM invite_codes'));
        self::assertEquals([TestData::friendship(2, 3)], $this->friendLinks()->ofUser(TestData::userId(2)));
        self::assertSame(1, $this->sql()->value('SELECT COUNT(*) FROM friendships'));
        self::assertSame(
            [['blocker_id' => TestData::uuid(2), 'blocked_id' => TestData::uuid(3)]],
            $this->sql()->rows('SELECT blocker_id, blocked_id FROM blocks'),
        );
    }

    protected function inviteCodes(): InviteCodes
    {
        return new PdoInviteCodes(new Sql($this->database));
    }

    protected function friendLinks(): FriendLinks
    {
        return new PdoFriendLinks(new Sql($this->database));
    }

    protected function blocks(): Blocks
    {
        return new PdoBlocks(new Sql($this->database));
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
