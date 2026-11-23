<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use RuntimeException;

/**
 * The user has not blocked this user. Answered as 404 "block_not_found".
 *
 * Carries no message: the problem of the answer (Http\Problem\Problem) has the text.
 */
final class BlockNotFound extends RuntimeException
{
}
