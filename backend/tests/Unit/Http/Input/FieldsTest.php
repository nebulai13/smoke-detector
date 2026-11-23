<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Http\Input;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Plan\ProductType;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\FieldError;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Http\Input\Fields;

/**
 * How an action reads and checks the members of the request body (HLD §7: explicit input
 * validation in the HTTP layer).
 */
final class FieldsTest extends TestCase
{
    public function test_valid_members_are_returned_with_their_types_and_nothing_is_wrong(): void
    {
        $fields = new Fields(
            ['email' => ' Anna@Example.org ', 'password' => ' secret ', 'label' => 'Phone', 'consents' => ['age18' => true, 'art9' => false]],
            ['email', 'password', 'label', 'missing', 'consents.age18', 'consents.art9'],
        );

        self::assertSame('anna@example.org', $fields->make('email', EmailAddress::fromInput(...))?->value);
        // A plain string is handed over exactly as it came.
        self::assertSame(' secret ', $fields->string('password'));
        self::assertSame('Phone', $fields->optionalString('label'));
        self::assertNull($fields->optionalString('missing'));
        self::assertNull($fields->makeOptional('missing', EmailAddress::fromInput(...)));
        self::assertTrue($fields->boolean('consents.age18'));
        self::assertFalse($fields->boolean('consents.art9'));
        self::assertFalse($fields->hasErrors());
    }

    public function test_a_missing_required_member_is_reported_once_with_its_name(): void
    {
        $fields = new Fields([], ['email', 'password', 'accepted', 'consents.age18', 'consents.art9']);

        self::assertNull($fields->string('password'));
        self::assertNull($fields->make('email', EmailAddress::fromInput(...)));
        self::assertNull($fields->boolean('accepted'));
        // A missing object is one error, not one per member of it.
        self::assertNull($fields->boolean('consents.age18'));
        self::assertNull($fields->boolean('consents.art9'));

        self::assertEquals(
            [
                new FieldError('password', ErrorCode::Required, 'Is required.'),
                new FieldError('email', ErrorCode::Required, 'Is required.'),
                new FieldError('accepted', ErrorCode::Required, 'Is required.'),
                new FieldError('consents', ErrorCode::Required, 'Is required.'),
            ],
            $fields->failure()->errors,
        );
    }

    public function test_a_member_of_the_wrong_type_is_reported_and_never_converted(): void
    {
        $fields = new Fields(
            ['a' => 42, 'b' => null, 'c' => ['x'], 'd' => true, 'e' => 'true', 'f' => 1, 'g' => null, 'h' => 0, 'i' => 1.5],
            ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'],
        );

        self::assertNull($fields->string('a'));
        self::assertNull($fields->string('b'));
        self::assertNull($fields->make('c', EmailAddress::fromInput(...)));
        self::assertNull($fields->optionalString('d'));
        self::assertNull($fields->boolean('e'));
        self::assertNull($fields->boolean('f'));
        self::assertNull($fields->optionalString('g'));
        self::assertNull($fields->boolean('h'));
        self::assertNull($fields->makeOptional('i', EmailAddress::fromInput(...)));

        self::assertEquals(
            [
                new FieldError('a', ErrorCode::WrongType, 'Must be a string.'),
                new FieldError('b', ErrorCode::WrongType, 'Must be a string.'),
                new FieldError('c', ErrorCode::WrongType, 'Must be a string.'),
                new FieldError('d', ErrorCode::WrongType, 'Must be a string.'),
                new FieldError('e', ErrorCode::WrongType, 'Must be true or false.'),
                new FieldError('f', ErrorCode::WrongType, 'Must be true or false.'),
                new FieldError('g', ErrorCode::WrongType, 'Must be a string.'),
                new FieldError('h', ErrorCode::WrongType, 'Must be true or false.'),
                new FieldError('i', ErrorCode::WrongType, 'Must be a string.'),
            ],
            $fields->failure()->errors,
        );
    }

    public function test_what_a_value_object_rejects_is_reported_with_its_code_and_message(): void
    {
        $fields = new Fields(['email' => 'not-an-address', 'other' => 'x'], ['email', 'other']);

        self::assertNull($fields->make('email', EmailAddress::fromInput(...)));
        self::assertNull($fields->makeOptional('other', static fn (string $value): string => $value === 'x' ? throw new InvalidValue(ErrorCode::InvalidValue, 'Is not acceptable.') : $value));

        self::assertEquals(
            [new FieldError('email', ErrorCode::InvalidFormat, 'Must be an email address.'), new FieldError('other', ErrorCode::InvalidValue, 'Is not acceptable.')],
            $fields->failure()->errors,
        );
    }

    public function test_a_factory_that_fails_without_a_code_is_a_bug_and_not_a_field_error(): void
    {
        // A field error cannot be made without a code, so an exception that carries none is not
        // turned into one. It travels on and ends as a 500, where it is noticed.
        $fields = new Fields(['other' => 'x'], ['other']);

        try {
            $fields->make('other', static fn (string $value): string => throw new InvalidArgumentException('No code.'));
            self::fail('Expected the exception to be passed on.');
        } catch (InvalidArgumentException $passedOn) {
            self::assertSame('No code.', $passedOn->getMessage());
        }
        self::assertFalse($fields->hasErrors());
    }

    public function test_a_member_the_operation_does_not_know_is_rejected(): void
    {
        // A typo ("refreshin") must not be read as "not sent".
        $fields = new Fields(
            ['email' => 'a@example.org', 'emial' => 'x', 'consents' => ['age18' => true, 'age' => true], 7 => 'x'],
            ['email', 'consents.age18'],
        );

        self::assertEquals(
            [
                new FieldError('emial', ErrorCode::UnknownField, 'Unknown field.'),
                new FieldError('consents.age', ErrorCode::UnknownField, 'Unknown field.'),
                new FieldError('7', ErrorCode::UnknownField, 'Unknown field.'),
            ],
            $fields->failure()->errors,
        );
    }

    public function test_the_name_of_an_unknown_member_is_cut_at_a_character_and_stays_valid_text(): void
    {
        // 64 bytes at most, and never half a character: the name goes into a JSON response.
        $fields = new Fields([str_repeat('ä', 40) => 1, 'consents' => [str_repeat('🔥', 30) => true]], ['consents.age18']);

        $echoed = array_map(static fn (FieldError $error): string => $error->field, $fields->failure()->errors);

        self::assertSame([str_repeat('ä', 32), 'consents.' . str_repeat('🔥', 13)], $echoed);
        foreach ($echoed as $name) {
            self::assertTrue(mb_check_encoding($name, 'UTF-8'));
            self::assertLessThanOrEqual(64, strlen($name));
        }
    }

    public function test_the_name_of_an_unknown_member_is_cut_before_it_is_echoed(): void
    {
        $fields = new Fields([str_repeat('x', 500) => 1], ['email']);

        self::assertSame(str_repeat('x', 64), $fields->failure()->errors[0]->field);
    }

    public function test_an_object_member_must_be_an_object(): void
    {
        foreach (['yes', true, 1, null, [true, true]] as $value) {
            $fields = new Fields(['consents' => $value], ['consents.age18', 'consents.art9']);

            self::assertNull($fields->boolean('consents.age18'));
            self::assertNull($fields->boolean('consents.art9'));
            self::assertEquals([new FieldError('consents', ErrorCode::WrongType, 'Must be an object.')], $fields->failure()->errors);
        }
    }

    public function test_an_empty_object_reports_each_member_of_it_as_missing(): void
    {
        $fields = new Fields(['consents' => []], ['consents.age18', 'consents.art9']);

        $fields->boolean('consents.age18');
        $fields->boolean('consents.art9');

        self::assertEquals(
            [new FieldError('consents.age18', ErrorCode::Required, 'Is required.'), new FieldError('consents.art9', ErrorCode::Required, 'Is required.')],
            $fields->failure()->errors,
        );
    }

    public function test_members_of_an_object_inside_an_object_are_read_by_their_whole_path(): void
    {
        $fields = new Fields(
            ['consents' => ['age18' => ['accepted' => true, 'version' => 'age-v1'], 'art9' => ['accepted' => false, 'version' => 'art9-v2', 'note' => 'x']]],
            ['consents.age18.accepted', 'consents.age18.version', 'consents.art9.accepted', 'consents.art9.version', 'consents.art9.note'],
        );

        self::assertTrue($fields->boolean('consents.age18.accepted'));
        self::assertSame('age-v1', $fields->string('consents.age18.version'));
        self::assertFalse($fields->boolean('consents.art9.accepted'));
        self::assertSame('art9-v2', $fields->optionalString('consents.art9.version'));
        self::assertSame('x', $fields->optionalString('consents.art9.note'));
        self::assertFalse($fields->hasErrors());
    }

    public function test_a_missing_object_at_any_depth_is_one_error_with_its_path(): void
    {
        $allowed = ['consents.age18.accepted', 'consents.age18.version', 'consents.art9.accepted', 'consents.art9.version', 'consents.art9.note'];

        $withoutTheOuter = new Fields([], $allowed);
        $withoutAnInner = new Fields(['consents' => ['age18' => ['accepted' => true, 'version' => 'v']]], $allowed);
        foreach ([$withoutTheOuter, $withoutAnInner] as $fields) {
            foreach (['consents.age18.accepted', 'consents.age18.version', 'consents.art9.accepted', 'consents.art9.version'] as $field) {
                str_ends_with($field, 'accepted') ? $fields->boolean($field) : $fields->string($field);
            }
            // Left out, and allowed to be: nothing is reported for it, at whatever depth it is missing.
            self::assertNull($fields->optionalString('consents.art9.note'));
        }

        self::assertEquals([new FieldError('consents', ErrorCode::Required, 'Is required.')], $withoutTheOuter->failure()->errors);
        self::assertEquals([new FieldError('consents.art9', ErrorCode::Required, 'Is required.')], $withoutAnInner->failure()->errors);
    }

    public function test_an_object_inside_an_object_must_be_an_object_and_its_members_are_reported_when_missing(): void
    {
        $allowed = ['consents.age18.accepted', 'consents.age18.version', 'consents.art9.accepted', 'consents.art9.version', 'consents.art9.note'];
        // What an older client sends: the consent itself where the object belongs.
        $fields = new Fields(['consents' => ['age18' => true, 'art9' => []]], $allowed);

        self::assertNull($fields->boolean('consents.age18.accepted'));
        self::assertNull($fields->string('consents.age18.version'));
        self::assertNull($fields->boolean('consents.art9.accepted'));
        self::assertNull($fields->string('consents.art9.version'));
        self::assertNull($fields->optionalString('consents.art9.note'));

        self::assertEquals(
            [
                new FieldError('consents.age18', ErrorCode::WrongType, 'Must be an object.'),
                new FieldError('consents.art9.accepted', ErrorCode::Required, 'Is required.'),
                new FieldError('consents.art9.version', ErrorCode::Required, 'Is required.'),
            ],
            $fields->failure()->errors,
        );
        foreach ([[1, 2], 'yes', 7, null] as $notAnObject) {
            $fields = new Fields(['consents' => ['age18' => $notAnObject]], $allowed);
            $fields->string('consents.age18.version');
            self::assertEquals([new FieldError('consents.age18', ErrorCode::WrongType, 'Must be an object.')], $fields->failure()->errors);
            // An optional member of something that is no object is simply not there.
            $fields = new Fields(['consents' => ['art9' => $notAnObject]], $allowed);
            self::assertNull($fields->optionalString('consents.art9.note'));
            self::assertFalse($fields->hasErrors());
        }
    }

    public function test_a_member_the_operation_does_not_know_is_rejected_at_any_depth(): void
    {
        $fields = new Fields(
            [
                'consents' => [
                    'age18' => ['accepted' => true, 'version' => 'v', 'signature' => 'x', 'more' => ['deep' => 1]],
                    'art10' => ['accepted' => true],
                ],
            ],
            ['consents.age18.accepted', 'consents.age18.version'],
        );

        self::assertEquals(
            [
                new FieldError('consents.age18.signature', ErrorCode::UnknownField, 'Unknown field.'),
                new FieldError('consents.age18.more', ErrorCode::UnknownField, 'Unknown field.'),
                new FieldError('consents.art10', ErrorCode::UnknownField, 'Unknown field.'),
            ],
            $fields->failure()->errors,
        );
        // A name that only begins like a known one is unknown too.
        $fields = new Fields(['consents' => ['age1' => 1], 'consent' => 1, 'consents.age18' => 1], ['consents.age18.accepted']);
        self::assertEquals(
            ['consents.age1', 'consent', 'consents.age18'],
            array_map(static fn (FieldError $error): string => $error->field, $fields->failure()->errors),
        );
    }

    public function test_an_action_can_add_its_own_error_and_a_field_is_reported_once(): void
    {
        $fields = new Fields(['password' => 'short'], ['password']);

        $fields->reject('password', ErrorCode::TooShort, 'Must be 10 to 200 characters long.');
        $fields->reject('password', ErrorCode::InvalidValue, 'Another problem with the same field.');
        $fields->rejectIf('other', null);
        $fields->rejectIf('other', new InvalidValue(ErrorCode::InvalidValue, 'Is wrong.'));

        self::assertTrue($fields->hasErrors());
        self::assertEquals(
            [new FieldError('password', ErrorCode::TooShort, 'Must be 10 to 200 characters long.'), new FieldError('other', ErrorCode::InvalidValue, 'Is wrong.')],
            $fields->failure()->errors,
        );
    }

    public function test_no_error_message_repeats_what_the_client_sent(): void
    {
        $fields = new Fields(['email' => 'SECRET-VALUE', 'password' => ['SECRET-VALUE']], ['email', 'password']);
        $fields->make('email', EmailAddress::fromInput(...));
        $fields->string('password');

        foreach ($fields->failure()->errors as $error) {
            self::assertStringNotContainsString('SECRET-VALUE', $error->message);
        }
    }

    public function test_asking_for_the_failure_when_nothing_is_wrong_is_a_programming_error(): void
    {
        $this->expectException(LogicException::class);

        (new Fields([], []))->failure();
    }

    public function test_reading_a_member_the_operation_did_not_declare_is_a_programming_error(): void
    {
        $this->expectException(LogicException::class);

        (new Fields(['email' => 'x'], ['email']))->string('password');
    }

    public function test_whole_numbers_are_read_as_integers(): void
    {
        $fields = new Fields(['a' => 12, 'b' => 0, 'c' => -3], ['a', 'b', 'c', 'missing']);

        self::assertSame(12, $fields->integer('a'));
        self::assertSame(0, $fields->integer('b'));
        self::assertSame(-3, $fields->optionalInteger('c'));
        self::assertNull($fields->optionalInteger('missing'));
        self::assertFalse($fields->hasErrors());
    }

    public function test_anything_but_a_whole_number_is_not_an_integer_and_is_never_converted(): void
    {
        // "f" is what a number beyond the integer range arrives as (JsonBodyMiddleware).
        $fields = new Fields(
            ['a' => '12', 'b' => 12.5, 'c' => 12.0, 'd' => true, 'e' => null, 'f' => '99999999999999999999', 'g' => [12]],
            ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'missing'],
        );

        foreach (['a', 'b', 'c', 'd'] as $field) {
            self::assertNull($fields->integer($field));
        }
        foreach (['e', 'f', 'g'] as $field) {
            self::assertNull($fields->optionalInteger($field));
        }
        self::assertNull($fields->integer('missing'));

        self::assertEquals(
            [
                new FieldError('a', ErrorCode::WrongType, 'Must be a whole number.'),
                new FieldError('b', ErrorCode::WrongType, 'Must be a whole number.'),
                new FieldError('c', ErrorCode::WrongType, 'Must be a whole number.'),
                new FieldError('d', ErrorCode::WrongType, 'Must be a whole number.'),
                new FieldError('e', ErrorCode::WrongType, 'Must be a whole number.'),
                new FieldError('f', ErrorCode::WrongType, 'Must be a whole number.'),
                new FieldError('g', ErrorCode::WrongType, 'Must be a whole number.'),
                new FieldError('missing', ErrorCode::Required, 'Is required.'),
            ],
            $fields->failure()->errors,
        );
    }

    public function test_a_member_with_a_fixed_set_of_values_is_read_as_its_enum(): void
    {
        $fields = new Fields(['locale' => 'en', 'product' => 'heated_tobacco'], ['locale', 'product']);

        self::assertSame(Locale::En, $fields->enum('locale', Locale::class));
        self::assertSame(ProductType::HeatedTobacco, $fields->enum('product', ProductType::class));
        self::assertFalse($fields->hasErrors());
    }

    public function test_a_value_outside_the_set_is_reported_with_the_values_that_are_allowed(): void
    {
        $fields = new Fields(['a' => 'fr', 'b' => 'EN', 'c' => 1, 'd' => ''], ['a', 'b', 'c', 'd', 'missing']);

        foreach (['a', 'b', 'c', 'd', 'missing'] as $field) {
            self::assertNull($fields->enum($field, Locale::class));
        }

        self::assertEquals(
            [
                new FieldError('a', ErrorCode::InvalidValue, 'Must be one of: de, en.'),
                new FieldError('b', ErrorCode::InvalidValue, 'Must be one of: de, en.'),
                new FieldError('c', ErrorCode::WrongType, 'Must be a string.'),
                new FieldError('d', ErrorCode::InvalidValue, 'Must be one of: de, en.'),
                new FieldError('missing', ErrorCode::Required, 'Is required.'),
            ],
            $fields->failure()->errors,
        );
    }
}
