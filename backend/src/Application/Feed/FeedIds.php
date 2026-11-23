<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Feed;

/**
 * Makes the ids of feed entries (HLD §4.12 "No time"). Unlike IdGenerator, whose ids begin with their
 * time, a feed id is random: a client that gets it learns nothing about when the entry was written.
 */
interface FeedIds
{
    /**
     * @return string A random UUID (version 4) in lower case with dashes.
     */
    public function next(): string;
}
