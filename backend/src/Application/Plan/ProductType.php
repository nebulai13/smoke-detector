<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Plan;

/**
 * What a session was smoked with (HLD §4.3 "smoke_logs", FR-ACC-06 "usual product type").
 *
 * Scoring ignores it (FR-PLAN-09). A value that was released must stay: plans and logs have it stored.
 */
enum ProductType: string
{
    case Cigarette = 'cigarette';
    case Rolled = 'rolled';
    case Cigar = 'cigar';
    case Pipe = 'pipe';
    case Shisha = 'shisha';
    case Vape = 'vape';
    case HeatedTobacco = 'heated_tobacco';
    case Other = 'other';
}
