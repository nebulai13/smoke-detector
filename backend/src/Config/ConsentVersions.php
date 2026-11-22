<?php

declare(strict_types=1);

namespace SmokeDetector\Config;

use InvalidArgumentException;

/**
 * Which version of each consent text is in force ("consents" section of the settings).
 *
 * A consent is stored with the version the user agreed to (FR-ACC-02, LEG-03). The app sends the
 * version of the text it showed with every registration, and only the version named here is
 * accepted (RegisterUser). GET /legal/consents tells the apps which versions these are. When a text
 * changes, its version here must change with it, in the same deploy as the new text: from then on
 * an app that still shows the old text cannot register anybody.
 */
final readonly class ConsentVersions
{
    public function __construct(
        /** The wording of "I am 18 or older". */
        public string $age18,
        /** The wording of the consent to the processing of smoking data (GDPR Art. 9). */
        public string $art9,
        /** The privacy policy. */
        public string $privacy,
    ) {
        foreach (['age18' => $age18, 'art9' => $art9, 'privacy' => $privacy] as $key => $version) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,31}\z/D', $version) !== 1) {
                throw new InvalidArgumentException(
                    'Settings: ' . Settings::describe("consents.{$key}") . ' must be 1 to 32 characters of A-Z, a-z, '
                    . '0-9, ".", "_" or "-" and start with a letter or a digit.',
                );
            }
        }
    }
}
