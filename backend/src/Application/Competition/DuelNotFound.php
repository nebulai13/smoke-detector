<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

use RuntimeException;

/**
 * The user has no duel with this id: it does not exist, or it is a duel of others. The answer does
 * not tell which. Answered as 404 "duel_not_found".
 *
 * Carries no message: the problem of the answer (Http\Problem\Problem) has the text.
 */
final class DuelNotFound extends RuntimeException
{
}
