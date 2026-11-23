<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use RuntimeException;

/**
 * There is no open friend request between the two users in the direction the operation needs, or
 * the other account does not exist. Answered as 404 "friend_request_not_found".
 *
 * Carries no message: the problem of the answer (Http\Problem\Problem) has the text.
 */
final class FriendRequestNotFound extends RuntimeException
{
}
