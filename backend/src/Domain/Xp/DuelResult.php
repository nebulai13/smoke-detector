<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Xp;

/**
 * How a duel ended for one of its two participants (SRS Appendix A).
 */
enum DuelResult: string
{
    case Won = 'won';
    case Draw = 'draw';
    /** Lost, but the duel ran to its end. */
    case Lost = 'lost';
    /** The duel was abandoned before its end, so there is no winner to reward. */
    case Forfeited = 'forfeited';
}
