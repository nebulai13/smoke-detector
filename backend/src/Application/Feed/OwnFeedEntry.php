<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Feed;

use RuntimeException;

/**
 * Nobody reacts to an own entry (HLD §4.13). Answered as 409 "own_feed_entry".
 *
 * Carries no message: the problem of the answer (Http\Problem\Problem) has the text.
 */
final class OwnFeedEntry extends RuntimeException
{
}
