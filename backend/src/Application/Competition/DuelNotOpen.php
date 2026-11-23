<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use RuntimeException;

/**
 * The duel is not a proposal that can be answered or taken back (any more): it was answered, it
 * expired, it is over, or the user is the wrong one of the two for this. Answered as 409
 * "duel_not_open".
 */
final class DuelNotOpen extends RuntimeException
{
}
