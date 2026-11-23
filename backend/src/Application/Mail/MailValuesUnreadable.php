<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Mail;

use RuntimeException;

/**
 * The stored values of a queued mail cannot be decrypted: they were damaged, or `jwt.secret` was
 * changed after the mail was queued.
 *
 * The message is fixed and there is no previous exception: neither the stored text nor anything
 * that was decrypted from it may travel on into a log.
 */
final class MailValuesUnreadable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The values of a queued mail cannot be read.');
    }
}
