<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Account;

/**
 * What a user must agree to before an account is created (FR-ACC-02).
 */
enum ConsentType: string
{
    /** "I am 18 or older." */
    case Age18 = 'age18';
    /** Explicit consent to the processing of smoking data, which is health data (GDPR Art. 9). */
    case Art9 = 'art9';
    /** The privacy policy. */
    case Privacy = 'privacy';
}
