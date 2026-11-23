<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use RuntimeException;

/**
 * FR-COMP-05: the two have an open duel already (proposed or running), whoever proposed it.
 * Answered as 409 "duel_exists".
 */
final class DuelExists extends RuntimeException
{
}
