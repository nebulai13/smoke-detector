<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Feed;

/**
 * What an entry of the activity feed is about (HLD §4.12, SRS FR-SOC-01). The values are stored and
 * sent to the clients, so they must stay stable.
 */
enum FeedEventType: string
{
    /** The detail is the level reached. */
    case LevelUp = 'level_up';
    /** The detail is the key of the badge. */
    case BadgeEarned = 'badge_earned';
    /** No detail; the other user is the opponent. */
    case DuelWon = 'duel_won';
    /** No detail; the other user is the opponent. */
    case DuelDraw = 'duel_draw';
    /** The detail is the key of the health milestone. */
    case HealthMilestone = 'health_milestone';
}
