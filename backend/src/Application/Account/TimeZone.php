<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Account;

use DateTimeZone;
use SmokeDetector\Application\Validation\ErrorCode;
use SmokeDetector\Application\Validation\InvalidValue;

/**
 * The IANA time zone all day logic of a user runs in (FR-ACC-01, FR-I18N-02).
 */
final readonly class TimeZone
{
    /**
     * Names a zone had in the tz database years ago and that browsers still report, with the name
     * the zone has now.
     *
     * Whether PHP knows an older name depends on the time zone data of the machine: a PHP with its
     * own data does, a PHP that uses the data of the operating system often does not (newer Linux
     * distributions ship the older names in a separate package). Translating here makes sign-up
     * behave the same on every machine, and day logic never meets a name the server cannot resolve.
     *
     * @var array<string, string>
     */
    public const array OLDER_NAMES = [
        'Africa/Asmera' => 'Africa/Asmara',
        'America/Buenos_Aires' => 'America/Argentina/Buenos_Aires',
        'America/Catamarca' => 'America/Argentina/Catamarca',
        'America/Cordoba' => 'America/Argentina/Cordoba',
        'America/Godthab' => 'America/Nuuk',
        'America/Indianapolis' => 'America/Indiana/Indianapolis',
        'America/Jujuy' => 'America/Argentina/Jujuy',
        'America/Louisville' => 'America/Kentucky/Louisville',
        'America/Mendoza' => 'America/Argentina/Mendoza',
        'Asia/Calcutta' => 'Asia/Kolkata',
        'Asia/Katmandu' => 'Asia/Kathmandu',
        'Asia/Rangoon' => 'Asia/Yangon',
        'Asia/Saigon' => 'Asia/Ho_Chi_Minh',
        'Atlantic/Faeroe' => 'Atlantic/Faroe',
        'Europe/Kiev' => 'Europe/Kyiv',
        'Pacific/Enderbury' => 'Pacific/Kanton',
        'Pacific/Ponape' => 'Pacific/Pohnpei',
        'Pacific/Truk' => 'Pacific/Chuuk',
    ];

    private function __construct(public string $name)
    {
    }

    /**
     * @throws InvalidValue With a message for the client that does not repeat the input.
     */
    public static function fromInput(string $input): self
    {
        // The list, not "new DateTimeZone($input)": PHP would also take offsets and abbreviations
        // ("+01:00", "cest"), which have no daylight saving rules.
        $name = self::nameIn($input, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC));
        if ($name === null) {
            throw new InvalidValue(ErrorCode::InvalidValue, 'Must be an IANA time zone name such as "Europe/Berlin".');
        }

        return new self($name);
    }

    /**
     * The name a zone is kept under, given the names the time zone data at hand knows.
     *
     * The current name where the data knows it. Data that is older than a renaming (Europe/Kyiv,
     * 2022) knows only the older name, and then that one is kept.
     *
     * @param list<string> $known Spelled exactly as in the tz database.
     *
     * @return string|null Null when the data knows the zone under neither name.
     */
    public static function nameIn(string $input, array $known): ?string
    {
        $current = self::OLDER_NAMES[$input] ?? null;
        if ($current !== null && in_array($current, $known, true)) {
            return $current;
        }

        return in_array($input, $known, true) ? $input : null;
    }
}
