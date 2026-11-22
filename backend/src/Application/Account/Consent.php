<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Account;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The record that a user agreed to one text in one version at one moment (FR-ACC-02, LEG-03).
 */
final readonly class Consent
{
    public function __construct(
        public ConsentType $type,
        /** The version of the text the user was shown: sent by the app and found to be the one in force (settings). */
        public string $version,
        public DateTimeImmutable $grantedAt,
    ) {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,31}\z/', $version) !== 1) {
            throw new InvalidArgumentException(
                'A consent version must be 1 to 32 characters of A-Z, a-z, 0-9, ".", "_" or "-".',
            );
        }
    }
}
