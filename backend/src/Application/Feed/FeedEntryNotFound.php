<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Feed;

use RuntimeException;

/**
 * There is no such entry in the feed of the user: it does not exist, or the user may not see it. The
 * answer does not tell which. Answered as 404 "feed_entry_not_found".
 *
 * Carries no message: the problem of the answer (Http\Problem\Problem) has the text.
 */
final class FeedEntryNotFound extends RuntimeException
{
}
