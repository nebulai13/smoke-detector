<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use RuntimeException;

/**
 * The other user is not a friend (for a block: not a friend and nobody with an open request
 * either), or does not exist: the answer does not tell which. Answered as 404 "friend_not_found".
 *
 * Carries no message: the problem of the answer (Http\Problem\Problem) has the text.
 */
final class FriendNotFound extends RuntimeException
{
}
