<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Competition;

/**
 * Where a duel stands (HLD §4.8 "Lifecycle", §5.3). The values are stored in `duels.status`, so
 * renaming one changes how stored duels are read.
 */
enum DuelStatus: string
{
    /** Waits for the friend, for 72 hours. */
    case Proposed = 'proposed';
    /** Accepted: running, or waiting for its last days to become final. */
    case Active = 'active';
    case Declined = 'declined';
    /** 72 hours passed without an answer. */
    case Expired = 'expired';
    /** The two stopped being friends while it ran. No winner, no XP. */
    case Cancelled = 'cancelled';
    /** Decided: it has a result. */
    case Completed = 'completed';
    /** An account was deleted while it ran. No winner, no XP. */
    case Forfeited = 'forfeited';
}
