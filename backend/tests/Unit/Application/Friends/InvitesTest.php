<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Friends;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Friends\InviteCode;
use SmokeDetector\Application\Friends\Invites;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Tests\Support\Fake\SequenceInviteCodes;
use SmokeDetector\Tests\Support\Fake\SequentialIdGenerator;
use SmokeDetector\Tests\Unit\Application\FriendsUseCaseTestCase;

/**
 * The personal invite code (SRS FR-FRND-01, HLD §4.7): ten characters that are hard to mix up and
 * impossible to guess. Whoever has the code can ask its owner to be friends, and nothing else.
 */
final class InvitesTest extends FriendsUseCaseTestCase
{
    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->givenUser();
    }

    public function test_FR_FRND_01_a_code_is_ten_characters_without_the_ones_that_are_mixed_up(): void
    {
        self::assertSame('23456789ABCDEFGHJKLMNPQRSTUVWXYZ', InviteCode::ALPHABET);
        self::assertSame(10, InviteCode::LENGTH);
        self::assertSame('K7M2QX9RTB', InviteCode::fromInput('K7M2QX9RTB')->value);
        self::assertSame('23456789AZ', InviteCode::fromInput('23456789AZ')->value);
    }

    public function test_FR_FRND_01_a_code_typed_in_small_letters_is_the_same_code(): void
    {
        self::assertSame('K7M2QX9RTB', InviteCode::fromInput('k7m2qx9rtb')->value);
        self::assertEquals(InviteCode::fromInput('K7M2QX9RTB'), InviteCode::fromInput('k7M2qx9RTb'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function noCodes(): iterable
    {
        yield 'empty' => [''];
        yield 'nine characters' => ['K7M2QX9RT'];
        yield 'eleven characters' => ['K7M2QX9RTBB'];
        yield 'a zero' => ['K7M2QX9RT0'];
        yield 'a one' => ['17M2QX9RTB'];
        yield 'the letter I' => ['K7M2QI9RTB'];
        yield 'the letter O' => ['K7M2QO9RTB'];
        yield 'the small letter o' => ['k7m2qo9rtb'];
        yield 'a space in front' => [' K7M2QX9RTB'];
        yield 'a line break behind' => ["K7M2QX9RTB\n"];
        yield 'a hyphen' => ['K7M2Q-X9RTB'];
        yield 'ten characters and a hyphen' => ['K7M2-QX9RT'];
        yield 'an umlaut' => ['K7M2QX9RTÄ'];
        yield 'full-width digits' => ['２３４５６７８９２３'];
    }

    #[DataProvider('noCodes')]
    public function test_FR_FRND_01_anything_else_is_no_code(string $input): void
    {
        try {
            InviteCode::fromInput($input);
            self::fail('Should have been refused.');
        } catch (InvalidValue $refused) {
            self::assertSame(ErrorCode::InvalidFormat, $refused->errorCode);
            // The message does not repeat what was sent.
            self::assertSame('An invite code has 10 characters out of 23456789ABCDEFGHJKLMNPQRSTUVWXYZ.', $refused->getMessage());
        }
    }

    public function test_FR_FRND_01_the_first_look_makes_the_code_and_every_later_look_shows_the_same(): void
    {
        $code = $this->invites()->codeOf($this->anna->id);

        self::assertSame(SequenceInviteCodes::code(1), $code->value);
        self::assertEquals($this->anna->id, $this->inviteCodes->owner($code));
        self::assertEquals($this->now(), $this->inviteCodes->madeAt($this->anna->id));

        $this->clock->advanceSeconds(3600);
        self::assertEquals($code, $this->invites()->codeOf($this->anna->id));
        // No second code was made, and the first one keeps its time.
        self::assertSame(1, $this->codes->made);
        self::assertEquals($this->now()->modify('-1 hour'), $this->inviteCodes->madeAt($this->anna->id));
    }

    public function test_FR_FRND_01_a_renewed_code_replaces_the_old_one_at_once(): void
    {
        $old = $this->givenCode($this->anna);

        $new = $this->invites()->renew($this->anna->id);

        self::assertSame(SequenceInviteCodes::code(1), $new->value);
        self::assertEquals($new, $this->inviteCodes->ofUser($this->anna->id));
        self::assertNull($this->inviteCodes->owner($old));
        self::assertEquals($this->now(), $this->inviteCodes->madeAt($this->anna->id));
        self::assertEquals($new, $this->invites()->codeOf($this->anna->id));
    }

    public function test_FR_FRND_01_renewing_without_a_code_makes_the_first_one(): void
    {
        $code = $this->invites()->renew($this->anna->id);

        self::assertEquals($code, $this->inviteCodes->ofUser($this->anna->id));
        self::assertSame(1, $this->codes->made);
    }

    public function test_FR_FRND_01_a_code_that_somebody_else_has_is_not_given_out_twice(): void
    {
        $bob = $this->givenUser('bob@example.org');
        $this->givenCode($bob, 'K7M2QX9RTB');
        $this->codes->script = ['K7M2QX9RTB', 'K7M2QX9RTB'];

        $code = $this->invites()->codeOf($this->anna->id);

        // The third try was free.
        self::assertSame(SequenceInviteCodes::code(3), $code->value);
        self::assertEquals($bob->id, $this->inviteCodes->owner(InviteCode::fromInput('K7M2QX9RTB')));
        self::assertEquals($this->anna->id, $this->inviteCodes->owner($code));
    }

    public function test_FR_FRND_01_after_five_codes_that_are_taken_no_further_one_is_tried(): void
    {
        self::assertSame(5, Invites::MAX_TRIES);
        $bob = $this->givenUser('bob@example.org');
        $this->givenCode($bob, 'K7M2QX9RTB');

        // The fifth try is still used.
        $this->codes->script = array_fill(0, 4, 'K7M2QX9RTB');
        self::assertSame(SequenceInviteCodes::code(5), $this->invites()->renew($this->anna->id)->value);

        // Five taken codes in a row do not happen by chance: something is wrong with the generator.
        $this->codes->script = array_fill(0, 5, 'K7M2QX9RTB');
        $this->codes->made = 0;
        try {
            $this->invites()->renew($this->anna->id);
            self::fail('Should have given up.');
        } catch (RuntimeException $failure) {
            self::assertSame('No unused invite code could be made.', $failure->getMessage());
        }
        self::assertSame(5, $this->codes->made);
        // The code from before is still hers: the failed renewal changed nothing.
        self::assertSame(SequenceInviteCodes::code(5), $this->inviteCodes->ofUser($this->anna->id)?->value);
    }

    public function test_FR_FRND_01_two_first_looks_at_the_same_moment_end_with_one_code(): void
    {
        // While this request waits for the account, another one of the same user makes the code.
        $this->users->beforeLock = function (): void {
            $this->inviteCodes->assign($this->anna->id, InviteCode::fromInput('K7M2QX9RTB'), $this->now());
        };

        $code = $this->invites()->codeOf($this->anna->id);

        self::assertSame('K7M2QX9RTB', $code->value);
        self::assertSame(0, $this->codes->made);
        self::assertSame([$this->anna->id->value], $this->users->lockLog);
    }

    public function test_FR_FRND_01_a_renewal_locks_the_account_too(): void
    {
        $this->invites()->renew($this->anna->id);

        self::assertSame([$this->anna->id->value], $this->users->lockLog);
    }

    public function test_FR_ACC_08_an_account_that_was_deleted_in_the_meantime_gets_no_code(): void
    {
        $nobody = UserId::fromString(SequentialIdGenerator::id(999));

        foreach ([fn () => $this->invites()->codeOf($nobody), fn () => $this->invites()->renew($nobody)] as $look) {
            try {
                $look();
                self::fail('Should have been refused.');
            } catch (AccountNotFound) {
                self::assertNull($this->inviteCodes->ofUser($nobody));
            }
        }
        self::assertSame(0, $this->codes->made);
    }
}
