<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Friends;

use SmokeDetector\Application\Friends\InviteCode;
use SmokeDetector\Application\Friends\InviteCodeGenerator;

/**
 * Invite codes from the operating system's secure random source (HLD §4.7: 10 characters out of 32,
 * 50 bits).
 */
final readonly class RandomInviteCodes implements InviteCodeGenerator
{
    public function next(): InviteCode
    {
        $code = '';
        for ($i = 0; $i < InviteCode::LENGTH; $i++) {
            // random_int() draws without bias over the whole range (no modulo), and throws when the
            // system has no secure randomness. It never returns a weak number.
            $code .= InviteCode::ALPHABET[random_int(0, strlen(InviteCode::ALPHABET) - 1)];
        }

        return InviteCode::fromInput($code);
    }
}
