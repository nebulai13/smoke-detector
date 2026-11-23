<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Account;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;
use SmokeDetector\Application\Account\Avatar;
use SmokeDetector\Application\Account\DisplayName;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Account\TimeZone;
use SmokeDetector\Application\Account\UserId;

/**
 * The profile values of a registration (FR-ACC-01; limits from HLD §4.5 "Account").
 */
final class ProfileValuesTest extends TestCase
{
    public function test_FR_ACC_01_a_display_name_has_2_to_30_characters(): void
    {
        self::assertSame('Jo', DisplayName::fromInput('Jo')->value);
        self::assertSame(str_repeat('ä', 30), DisplayName::fromInput(str_repeat('ä', 30))->value);
        // Counted in characters, not bytes: an emoji is one.
        self::assertSame('🔥🔥', DisplayName::fromInput('🔥🔥')->value);
    }

    public function test_a_display_name_loses_the_space_around_it_but_not_inside(): void
    {
        self::assertSame('Anna  B.', DisplayName::fromInput("  Anna  B.\u{00A0}\t")->value);
    }

    public function test_FR_ACC_01_a_display_name_may_contain_emoji_that_are_joined_from_several(): void
    {
        // The zero-width joiner is invisible, and allowed only where it builds one emoji out of several.
        self::assertSame("Jo 👩🏽‍🚀", DisplayName::fromInput("Jo 👩🏽‍🚀")->value);
        self::assertSame("❤️‍🔥 Anna", DisplayName::fromInput("❤️‍🔥 Anna")->value);
        self::assertSame("👨‍👩‍👧", DisplayName::fromInput("👨‍👩‍👧")->value);
    }

    /**
     * @return iterable<string, array{string, ErrorCode, string}>
     */
    public static function invalidDisplayNames(): iterable
    {
        $length = 'Must be 2 to 30 characters long.';
        $visible = 'Must have at least 2 visible characters.';
        $characters = 'Must not contain control characters, invisible characters or line breaks.';

        yield 'empty' => ['', ErrorCode::TooShort, $length];
        yield 'one character' => ['J', ErrorCode::TooShort, $length];
        yield 'one character and space' => [' J ', ErrorCode::TooShort, $length];
        yield '31 characters' => [str_repeat('a', 31), ErrorCode::TooLong, $length];
        yield 'line break' => ["Anna\nBob", ErrorCode::InvalidFormat, $characters];
        yield 'tab inside' => ["Anna\tBob", ErrorCode::InvalidFormat, $characters];
        yield 'NUL' => ["Anna\0Bob", ErrorCode::InvalidFormat, $characters];
        yield 'line separator' => ["Anna\u{2028}Bob", ErrorCode::InvalidFormat, $characters];
        yield 'paragraph separator' => ["Anna\u{2029}Bob", ErrorCode::InvalidFormat, $characters];
        // Would let a name run "backwards" over the text next to it in a friend list.
        yield 'right-to-left override' => ["Anna\u{202E}Bob", ErrorCode::InvalidFormat, $characters];
        yield 'isolate' => ["Anna\u{2066}Bob", ErrorCode::InvalidFormat, $characters];
        yield 'invalid UTF-8' => ["Ann\xFF", ErrorCode::InvalidFormat, $characters];
        // Invisible characters: a name made of them shows as nothing, and one of them inside a
        // name makes a second "Anna" that no friend can tell from the first.
        yield 'only zero-width spaces' => ["\u{200B}\u{200B}\u{200B}", ErrorCode::InvalidFormat, $characters];
        yield 'zero-width space inside' => ["An\u{200B}na", ErrorCode::InvalidFormat, $characters];
        yield 'zero-width space at the end' => ["Anna\u{200B}", ErrorCode::InvalidFormat, $characters];
        yield 'word joiner and BOM' => ["\u{2060}\u{FEFF}", ErrorCode::InvalidFormat, $characters];
        yield 'soft hyphens' => ["\u{00AD}\u{00AD}", ErrorCode::InvalidFormat, $characters];
        yield 'left-to-right and right-to-left mark' => ["\u{200E}\u{200F}", ErrorCode::InvalidFormat, $characters];
        yield 'arabic letter mark' => ["Anna\u{061C}", ErrorCode::InvalidFormat, $characters];
        yield 'zero-width non-joiner' => ["An\u{200C}na", ErrorCode::InvalidFormat, $characters];
        yield 'zero-width joiner between letters' => ["An\u{200D}na", ErrorCode::InvalidFormat, $characters];
        yield 'zero-width joiner after an emoji but before a letter' => ["🔥\u{200D}a", ErrorCode::InvalidFormat, $characters];
        yield 'zero-width joiner at the end' => ["🔥🔥\u{200D}", ErrorCode::InvalidFormat, $characters];
        yield 'only zero-width joiners' => ["\u{200D}\u{200D}", ErrorCode::InvalidFormat, $characters];
        // Letters and symbols by their class, but nothing to see.
        yield 'hangul fillers' => ["\u{3164}\u{3164}", ErrorCode::TooShort, $visible];
        yield 'hangul choseong and jungseong filler' => ["\u{115F}\u{1160}", ErrorCode::TooShort, $visible];
        yield 'halfwidth hangul filler' => ["\u{FFA0}\u{FFA0}\u{FFA0}", ErrorCode::TooShort, $visible];
        yield 'braille blanks' => ["\u{2800}\u{2800}", ErrorCode::TooShort, $visible];
        // U+1D159 MUSICAL SYMBOL NULL NOTEHEAD: a "symbol" by its category, and drawn as nothing.
        yield 'musical null noteheads' => ["\u{1D159}\u{1D159}", ErrorCode::TooShort, $visible];
        yield 'one letter and a null notehead' => ["A\u{1D159}", ErrorCode::TooShort, $visible];
        yield 'one letter and a filler' => ["A\u{3164}", ErrorCode::TooShort, $visible];
        yield 'one letter and combining marks' => ["A\u{0301}\u{0302}", ErrorCode::TooShort, $visible];
        yield 'one emoji with its variation selector' => ["❤\u{FE0F}", ErrorCode::TooShort, $visible];
    }

    #[DataProvider('invalidDisplayNames')]
    public function test_FR_ACC_01_a_display_name_outside_the_rules_is_rejected(string $input, ErrorCode $code, string $message): void
    {
        try {
            DisplayName::fromInput($input);
            self::fail('Expected the name to be rejected.');
        } catch (InvalidValue $rejected) {
            self::assertSame([$code, $message], [$rejected->errorCode, $rejected->getMessage()]);
        }
    }

    public function test_FR_ACC_01_an_avatar_is_one_of_the_presets(): void
    {
        self::assertSame('avatar-01', Avatar::fromInput('avatar-01')->key);
        self::assertSame('avatar-12', Avatar::fromInput('avatar-12')->key);
        self::assertCount(12, Avatar::PRESETS);
        self::assertSame(Avatar::PRESETS, array_values(array_unique(Avatar::PRESETS)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownAvatars(): iterable
    {
        yield 'empty' => [''];
        yield 'not in the list' => ['avatar-13'];
        yield 'other case' => ['Avatar-01'];
        yield 'with space' => [' avatar-01'];
        yield 'a path' => ['../avatar-01'];
        yield 'a URL' => ['https://example.org/me.png'];
    }

    #[DataProvider('unknownAvatars')]
    public function test_FR_ACC_01_an_avatar_that_is_not_a_preset_is_rejected(string $input): void
    {
        try {
            Avatar::fromInput($input);
            self::fail('Expected the value to be rejected.');
        } catch (InvalidValue $rejected) {
            self::assertSame([ErrorCode::InvalidValue, 'Must be one of the preset avatars.'], [$rejected->errorCode, $rejected->getMessage()]);
        }
    }

    public function test_FR_I18N_01_the_language_is_german_or_english(): void
    {
        self::assertSame(Locale::De, Locale::fromInput('de'));
        self::assertSame(Locale::En, Locale::fromInput('en'));
        self::assertSame('de', Locale::De->value);
        self::assertSame('en', Locale::En->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownLocales(): iterable
    {
        yield 'empty' => [''];
        yield 'other language' => ['fr'];
        yield 'with region' => ['de-DE'];
        yield 'other case' => ['DE'];
    }

    #[DataProvider('unknownLocales')]
    public function test_FR_I18N_01_another_language_is_rejected(string $input): void
    {
        try {
            Locale::fromInput($input);
            self::fail('Expected the value to be rejected.');
        } catch (InvalidValue $rejected) {
            self::assertSame([ErrorCode::InvalidValue, 'Must be "de" or "en".'], [$rejected->errorCode, $rejected->getMessage()]);
        }
    }

    public function test_FR_ACC_01_a_time_zone_is_an_iana_name(): void
    {
        self::assertSame('Europe/Berlin', TimeZone::fromInput('Europe/Berlin')->name);
        self::assertSame('UTC', TimeZone::fromInput('UTC')->name);
        self::assertSame('America/Argentina/Buenos_Aires', TimeZone::fromInput('America/Argentina/Buenos_Aires')->name);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function olderTimeZoneNames(): iterable
    {
        // Each of the current names has been in the tz database for more than fifteen years, so
        // every PHP knows it, whatever time zone data the machine has.
        yield 'India' => ['Asia/Calcutta', 'Asia/Kolkata'];
        yield 'Nepal' => ['Asia/Katmandu', 'Asia/Kathmandu'];
        yield 'Vietnam' => ['Asia/Saigon', 'Asia/Ho_Chi_Minh'];
        yield 'Argentina' => ['America/Buenos_Aires', 'America/Argentina/Buenos_Aires'];
        yield 'Indiana' => ['America/Indianapolis', 'America/Indiana/Indianapolis'];
        yield 'Faroe Islands' => ['Atlantic/Faeroe', 'Atlantic/Faroe'];
    }

    #[DataProvider('olderTimeZoneNames')]
    public function test_FR_ACC_01_an_older_name_that_browsers_still_report_is_kept_under_its_current_name(string $older, string $current): void
    {
        // Browsers report some zones under the name they had years ago. Whether PHP knows such a
        // name depends on the time zone data of the machine (a PHP that uses the system's data
        // often does not), so the name is translated here and the result is the same everywhere.
        self::assertSame($current, TimeZone::fromInput($older)->name);
        self::assertSame($current, TimeZone::fromInput($current)->name);
    }

    public function test_FR_ACC_01_a_zone_is_kept_under_the_name_the_servers_time_zone_data_knows(): void
    {
        // Time zone data from before a renaming knows only the older name: then that one is kept.
        self::assertSame('Europe/Kiev', TimeZone::nameIn('Europe/Kiev', ['Europe/Berlin', 'Europe/Kiev']));
        // Data that knows both, or only the current one: the current name.
        self::assertSame('Europe/Kyiv', TimeZone::nameIn('Europe/Kiev', ['Europe/Kiev', 'Europe/Kyiv']));
        self::assertSame('Europe/Kyiv', TimeZone::nameIn('Europe/Kiev', ['Europe/Kyiv']));
        self::assertSame('Europe/Kyiv', TimeZone::nameIn('Europe/Kyiv', ['Europe/Kyiv']));
        // A name that is in no table is taken as it is, when the data knows it.
        self::assertSame('Europe/Berlin', TimeZone::nameIn('Europe/Berlin', ['Europe/Berlin', 'Europe/Kiev']));
        // Known to nobody: neither as it is nor under another name.
        self::assertNull(TimeZone::nameIn('Europe/Kiev', ['Europe/Berlin']));
        self::assertNull(TimeZone::nameIn('Europe/Kyiv', ['Europe/Kiev']));
        self::assertNull(TimeZone::nameIn('europe/berlin', ['Europe/Berlin']));
        self::assertNull(TimeZone::nameIn('', ['Europe/Berlin']));
    }

    public function test_FR_ACC_01_the_table_of_older_names_leads_to_current_names_only(): void
    {
        self::assertNotSame([], TimeZone::OLDER_NAMES);
        foreach (TimeZone::OLDER_NAMES as $older => $current) {
            self::assertNotSame($older, $current);
            // No chains: a current name is never itself listed as an older one.
            self::assertArrayNotHasKey($current, TimeZone::OLDER_NAMES, "{$older} leads to {$current}, which is listed as older itself.");
            self::assertMatchesRegularExpression('#\\A[A-Z][A-Za-z_]+(/[A-Z][A-Za-z_]+)+\\z#', $current);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownTimeZones(): iterable
    {
        yield 'empty' => [''];
        yield 'an offset' => ['+01:00'];
        yield 'an abbreviation PHP would accept' => ['cest'];
        yield 'other case' => ['europe/berlin'];
        yield 'made up' => ['Europe/Atlantis'];
        yield 'with space' => ['Europe/Berlin '];
    }

    #[DataProvider('unknownTimeZones')]
    public function test_FR_ACC_01_something_that_is_not_an_iana_time_zone_is_rejected(string $input): void
    {
        try {
            TimeZone::fromInput($input);
            self::fail('Expected the value to be rejected.');
        } catch (InvalidValue $rejected) {
            self::assertSame([ErrorCode::InvalidValue, 'Must be an IANA time zone name such as "Europe/Berlin".'], [$rejected->errorCode, $rejected->getMessage()]);
        }
    }

    public function test_a_user_id_is_a_uuid_in_its_canonical_form(): void
    {
        $id = UserId::fromString('019a9279-2f6c-7b3e-8d1a-0123456789ab');

        self::assertSame('019a9279-2f6c-7b3e-8d1a-0123456789ab', $id->value);
        self::assertTrue($id->equals(UserId::fromString('019a9279-2f6c-7b3e-8d1a-0123456789ab')));
        self::assertFalse($id->equals(UserId::fromString('019a9279-2f6c-7b3e-8d1a-0123456789ac')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUserIds(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => ['019A9279-2F6C-7B3E-8D1A-0123456789AB'];
        yield 'without dashes' => ['019a92792f6c7b3e8d1a0123456789ab'];
        yield 'too short' => ['019a9279-2f6c-7b3e-8d1a-0123456789a'];
        yield 'too long' => ['019a9279-2f6c-7b3e-8d1a-0123456789abc'];
        yield 'not hexadecimal' => ['019a9279-2f6c-7b3e-8d1a-0123456789ag'];
        yield 'with a line break' => ["019a9279-2f6c-7b3e-8d1a-0123456789ab\n"];
        yield 'in braces' => ['{019a9279-2f6c-7b3e-8d1a-0123456789ab}'];
    }

    #[DataProvider('invalidUserIds')]
    public function test_something_that_is_not_a_canonical_uuid_is_not_a_user_id(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        UserId::fromString($input);
    }
}
