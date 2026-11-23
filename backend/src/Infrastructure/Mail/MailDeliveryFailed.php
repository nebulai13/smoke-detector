<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Mail;

use RuntimeException;

/**
 * The mail server did not take a mail.
 *
 * The message names the template and the kind of failure only. What the mail server answered is
 * left out on purpose, and so is the original exception: both can contain the recipient's address.
 */
final class MailDeliveryFailed extends RuntimeException
{
    public function __construct(string $template, string $reason)
    {
        parent::__construct("The mail \"{$template}\" could not be handed to the mail server ({$reason}).");
    }
}
