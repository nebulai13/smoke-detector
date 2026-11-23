<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

/**
 * Makes new invite codes (FR-FRND-01). Whether a code is taken already is not its business: the
 * use case tries the next one.
 */
interface InviteCodeGenerator
{
    /**
     * A code from a secure random source, every one of the 32^10 codes equally likely.
     */
    public function next(): InviteCode;
}
