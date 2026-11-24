<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Nudge;

use RuntimeException;

/**
 * The user has nudged this friend on the user's own today already (HLD §4.14), whether that nudge was
 * delivered or not. Answered as 409 "already_nudged".
 *
 * Carries no message: the problem of the answer (Http\Problem\Problem) has the text.
 */
final class AlreadyNudged extends RuntimeException
{
}
