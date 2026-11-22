<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use SensitiveParameter;
use SmokeDetector\Application\Account\Avatar;
use SmokeDetector\Application\Account\ConsentType;
use SmokeDetector\Application\Account\DisplayName;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Account\TimeZone;

/**
 * What a user submits to register (FR-ACC-01, FR-ACC-02).
 */
final readonly class Registration
{
    public function __construct(
        public EmailAddress $email,
        /** Exactly as typed. */
        #[SensitiveParameter]
        public string $password,
        public DisplayName $displayName,
        public Avatar $avatar,
        public Locale $locale,
        public TimeZone $timeZone,
        /** "I am 18 or older." */
        public GivenConsent $age18,
        /** Explicit consent to the processing of smoking data (GDPR Art. 9). */
        public GivenConsent $art9,
        /** The privacy policy. */
        public GivenConsent $privacy,
    ) {
    }

    public function consent(ConsentType $type): GivenConsent
    {
        return match ($type) {
            ConsentType::Age18 => $this->age18,
            ConsentType::Art9 => $this->art9,
            ConsentType::Privacy => $this->privacy,
        };
    }
}
