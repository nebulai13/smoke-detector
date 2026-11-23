<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use SmokeDetector\Application\Friends\InviteCode;
use SmokeDetector\Application\Friends\InviteCodeGenerator;

/**
 * Invite codes a test can predict: first the scripted ones, then 2222222223, 2222222224, …
 */
final class SequenceInviteCodes implements InviteCodeGenerator
{
    /** @var list<string> Codes to hand out first, for example one that is taken already. */
    public array $script = [];

    /** How many codes were asked for. */
    public int $made = 0;

    public function next(): InviteCode
    {
        $this->made++;
        if ($this->script !== []) {
            return InviteCode::fromInput((string) array_shift($this->script));
        }

        return InviteCode::fromInput(self::code($this->made));
    }

    /**
     * The code with this number, counted in the alphabet of the codes.
     */
    public static function code(int $number): string
    {
        $digits = strtr(base_convert((string) $number, 10, 32), '0123456789abcdefghijklmnopqrstuv', InviteCode::ALPHABET);

        return str_pad($digits, InviteCode::LENGTH, InviteCode::ALPHABET[0], STR_PAD_LEFT);
    }
}
