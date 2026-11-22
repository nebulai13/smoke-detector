<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Mail;

use SmokeDetector\Application\Account\EmailAddress;

/**
 * One mail, ready to be sent: plain text and the same content as simple HTML.
 */
final readonly class Mail
{
    public function __construct(
        public EmailAddress $to,
        /** Which template it was made from, e.g. "confirm_email". Safe to log, unlike the content. */
        public string $template,
        public string $subject,
        public string $text,
        public string $html,
    ) {
    }
}
