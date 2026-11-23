<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Friends\Blocks;
use SmokeDetector\Application\Friends\FriendLinks;
use SmokeDetector\Application\Friends\InviteCode;
use SmokeDetector\Application\Friends\InviteCodes;
use SmokeDetector\Tests\Support\Contract\FriendRepositoriesContract;
use SmokeDetector\Tests\Support\Fake\SequenceInviteCodes;
use SmokeDetector\Tests\Support\InMemory\InMemoryBlocks;
use SmokeDetector\Tests\Support\InMemory\InMemoryFriendLinks;
use SmokeDetector\Tests\Support\InMemory\InMemoryInviteCodes;
use SmokeDetector\Tests\Support\InMemory\InMemoryTransactionRunner;
use SmokeDetector\Tests\Support\TestData;

/**
 * The in-memory repositories of the friends behave like the PDO ones: both run the same contract
 * tests (the PDO ones in tests/Integration/Infrastructure/Repository). The use case unit tests rest
 * on that.
 */
final class InMemoryFriendRepositoriesTest extends TestCase
{
    use FriendRepositoriesContract;

    private InMemoryTransactionRunner $transactions;
    private InMemoryInviteCodes $inviteCodes;
    private InMemoryFriendLinks $friendLinks;
    private InMemoryBlocks $blocks;

    protected function setUp(): void
    {
        $this->transactions = new InMemoryTransactionRunner();
        $this->inviteCodes = new InMemoryInviteCodes($this->transactions);
        $this->friendLinks = new InMemoryFriendLinks($this->transactions);
        $this->blocks = new InMemoryBlocks($this->transactions);
    }

    public function test_what_a_failed_transaction_stored_is_undone_like_in_the_database(): void
    {
        $this->inviteCodes->assign(TestData::userId(1), InviteCode::fromInput('K7M2QX9RTB'), TestData::time('2026-11-17 12:00:00'));
        $this->friendLinks->save(TestData::friendship(1, 2));
        $this->blocks->add(TestData::block(1, 3));

        $passedOn = null;
        try {
            $this->transactions->run(function (): void {
                $this->inviteCodes->assign(TestData::userId(1), InviteCode::fromInput('ZZZZZZZZZZ'), TestData::time('2026-11-18 12:00:00'));
                $this->inviteCodes->assign(TestData::userId(2), InviteCode::fromInput('2222222222'), TestData::time('2026-11-18 12:00:00'));
                $this->friendLinks->delete(TestData::userId(1), TestData::userId(2));
                $this->friendLinks->save(TestData::friendship(3, 4));
                $this->blocks->remove(TestData::userId(1), TestData::userId(3));
                $this->blocks->add(TestData::block(2, 1));
                throw new RuntimeException('failed');
            });
        } catch (RuntimeException $failure) {
            $passedOn = $failure->getMessage();
        }

        self::assertSame('failed', $passedOn);
        self::assertEquals(InviteCode::fromInput('K7M2QX9RTB'), $this->inviteCodes->ofUser(TestData::userId(1)));
        self::assertEquals(TestData::time('2026-11-17 12:00:00'), $this->inviteCodes->madeAt(TestData::userId(1)));
        self::assertNull($this->inviteCodes->ofUser(TestData::userId(2)));
        self::assertEquals([TestData::friendship(1, 2)], $this->friendLinks->all());
        self::assertEquals([TestData::block(1, 3)], $this->blocks->all());
    }

    public function test_the_fake_generator_hands_out_scripted_codes_first_and_then_counts(): void
    {
        $codes = new SequenceInviteCodes();
        $codes->script = ['K7M2QX9RTB'];

        self::assertSame(['K7M2QX9RTB', '2222222224', '2222222225'], [$codes->next()->value, $codes->next()->value, $codes->next()->value]);
        self::assertSame(3, $codes->made);
        self::assertSame(['2222222223', '2222222232', '22222222ZZ'], [SequenceInviteCodes::code(1), SequenceInviteCodes::code(32), SequenceInviteCodes::code(1023)]);
    }

    protected function inviteCodes(): InviteCodes
    {
        return $this->inviteCodes;
    }

    protected function friendLinks(): FriendLinks
    {
        return $this->friendLinks;
    }

    protected function blocks(): Blocks
    {
        return $this->blocks;
    }

    protected function givenUsers(UserId ...$ids): void
    {
        // In memory, rows can exist without an account.
    }
}
