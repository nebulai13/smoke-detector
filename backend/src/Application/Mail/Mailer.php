<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Mail;

use Throwable;

/**
 * Hands a mail to the mail server (HLD §4.5 "Mail").
 *
 * Only the sender of the outbox calls this (SendQueuedMails, run by the cron job), never a use
 * case that answers a web request: that one queues its mail (AccountMails, MailOutbox). How long
 * the mail server takes, and whether it takes the mail at all, can therefore never show in a
 * response.
 */
interface Mailer
{
    /**
     * @throws Throwable When the mail server did not take the mail.
     */
    public function send(Mail $mail): void;
}
