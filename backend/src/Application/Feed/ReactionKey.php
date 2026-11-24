<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Feed;

/**
 * The three preset reactions to a feed entry (HLD §4.13, SRS FR-SOC-02). There is no free text. The
 * values are stored and sent to the clients, so they must stay stable.
 */
enum ReactionKey: string
{
    case Cheer = 'cheer';
    case Strong = 'strong';
    case KeepGoing = 'keep_going';
}
