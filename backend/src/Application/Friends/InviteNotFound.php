<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use RuntimeException;

/**
 * Nobody the user can ask has this invite code: it does not exist (any more), or one of the two
 * has blocked the other (HLD §4.7). Answered as 404 "invite_not_found".
 *
 * Carries no message: the problem of the answer (Http\Problem\Problem) has the text.
 */
final class InviteNotFound extends RuntimeException
{
}
