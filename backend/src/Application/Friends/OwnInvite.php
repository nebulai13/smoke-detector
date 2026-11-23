<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use RuntimeException;

/**
 * The invite code is the user's own: nobody asks themselves. Answered as 409 "own_invite".
 *
 * Carries no message: the problem of the answer (Http\Problem\Problem) has the text.
 */
final class OwnInvite extends RuntimeException
{
}
