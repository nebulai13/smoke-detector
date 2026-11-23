<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use RuntimeException;

/**
 * FR-COMP-05: the user's running duels and own open proposals are 5 already. Answered as 409
 * "duel_limit_reached".
 */
final class DuelLimitReached extends RuntimeException
{
}
