<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use RuntimeException;

/**
 * The owner of the invite code is a friend already. Answered as 409 "already_friends".
 *
 * Carries no message: the problem of the answer (Http\Problem\Problem) has the text.
 */
final class AlreadyFriends extends RuntimeException
{
}
