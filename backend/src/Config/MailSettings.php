<?php

declare(strict_types=1);

namespace SmokeDetector\Config;

use InvalidArgumentException;

/**
 * How mail is sent and who it comes from ("mail" section of the settings).
 */
final readonly class MailSettings
{
    public function __construct(
        /** A symfony/mailer transport DSN. It contains the SMTP password, hence a secret. */
        public Secret $dsn,
        public string $fromAddress,
        public string $fromName,
    ) {
        // Both values become mail headers, so a line break in them would add headers of its own.
        if (
            preg_match('/\A[\x21-\x7E]{3,254}\z/', $fromAddress) !== 1
            || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new InvalidArgumentException(
                'Settings: "mail.fromAddress" (environment variable MAIL_FROM_ADDRESS) must be an email address.',
            );
        }
        if (preg_match('/\A[^\p{C}]{1,80}\z/u', $fromName) !== 1) {
            throw new InvalidArgumentException(
                'Settings: "mail.fromName" (environment variable MAIL_FROM_NAME) must be 1 to 80 printable characters.',
            );
        }
    }
}
