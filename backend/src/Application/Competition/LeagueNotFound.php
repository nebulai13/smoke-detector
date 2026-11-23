<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use RuntimeException;

/**
 * The period has not begun for the user (HLD §4.9 "What a user sees"). Answered as 404
 * "league_not_found".
 *
 * Carries no message: the problem of the answer (Http\Problem\Problem) has the text.
 */
final class LeagueNotFound extends RuntimeException
{
}
