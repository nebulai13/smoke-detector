<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use RuntimeException;

/**
 * A user has at most 50 friends (FR-FRND-04, HLD §4.7 "Limit"): for a new request the sender's
 * friends and own open requests count, for an acceptance the friends of either of the two.
 * Answered as 409 "friend_limit_reached".
 *
 * Carries no message: the problem of the answer (Http\Problem\Problem) has the text.
 */
final class FriendLimitReached extends RuntimeException
{
}
