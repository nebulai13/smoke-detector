<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

/**
 * One consent as the app reports it with a registration (FR-ACC-02, LEG-03): whether the user
 * agreed, and to which version of the text. The app knows what it showed, the server does not.
 */
final readonly class GivenConsent
{
    public function __construct(
        /** Only true is a consent. */
        public bool $accepted,
        /** The version of the text the app displayed, exactly as the app sent it. */
        public string $version,
    ) {
    }
}
