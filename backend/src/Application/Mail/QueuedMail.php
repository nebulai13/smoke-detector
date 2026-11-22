<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Mail;

use DateTimeImmutable;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Account\UserId;

/**
 * One mail that waits in the outbox (HLD §4.3 "mail_outbox"): everything the sender needs to make
 * the mail later, outside the web request that caused it.
 */
final readonly class QueuedMail
{
    public function __construct(
        public string $id,
        /** The account the mail is about. When the account is deleted, the mail goes with it. */
        public UserId $userId,
        public MailKind $kind,
        public Locale $locale,
        public EmailAddress $to,
        /**
         * The values of the template (the links), encrypted by MailValuesCipher. A link carries the
         * one-time token in plain text, and the database otherwise only ever sees its hash.
         */
        public string $sealedValues,
        public DateTimeImmutable $createdAt,
        /** The sender leaves the mail alone until then: the next try, or the end of a run's hold on it. */
        public DateTimeImmutable $notBefore,
        /** How many tries to send it have failed. */
        public int $attempts,
    ) {
    }

    /**
     * From when on the mail is not worth sending any more: its link has stopped working.
     */
    public function expiresAt(): DateTimeImmutable
    {
        $lifetime = $this->kind->lifetimeSeconds();

        return $this->createdAt->modify("+{$lifetime} seconds");
    }
}
