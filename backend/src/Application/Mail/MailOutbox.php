<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Mail;

use DateTimeImmutable;

/**
 * The mails that wait to be sent (HLD §4.3 "mail_outbox", §4.5 "Mail").
 *
 * A web request never talks to the mail server: on the production server a request cannot hand its
 * response over and keep working, so the time the mail server takes would show in the response
 * time, and that would tell whether a mail was sent, which is whether an address has an account
 * (FR-ACC-01, FR-ACC-05). A use case puts the mail here instead, and the cron job sends it
 * (SendQueuedMails).
 */
interface MailOutbox
{
    /**
     * Queues a mail. Only inside the transaction of the use case, the one that stores the token of
     * the mail's link: either both are stored or neither is.
     */
    public function add(QueuedMail $mail): void;

    /**
     * Takes the mails that are due at $now (not_before <= $now), oldest first, at most $limit, and
     * sets them aside until $leaseUntil by moving their not_before there. Only inside a
     * transaction, which the caller ends before it sends anything.
     *
     * Mails that another run is taking at this moment are skipped, not waited for, and once the
     * transaction is committed the taken mails are not due for anybody. So two runs at the same
     * time never get the same mail.
     *
     * @return list<QueuedMail> As they were before they were set aside: with their own not_before.
     */
    public function claimDue(DateTimeImmutable $now, int $limit, DateTimeImmutable $leaseUntil): array;

    /**
     * Stores when the mail is due next and how many tries have failed. Outside a transaction.
     */
    public function reschedule(string $id, int $attempts, DateTimeImmutable $notBefore): void;

    /**
     * Removes the mail: it was handed to the mail server, or it is given up. Outside a
     * transaction. A mail that is gone already (its account was deleted meanwhile) is no error.
     */
    public function delete(string $id): void;

    /**
     * Removes every mail that was queued before $moment, whatever its template, language or state.
     * For the cleanup: a mail older than the longest-lived link is worth nothing, and the sender
     * would never take one whose template the running release does not know (after a rollback).
     * Only inside a transaction, which holds nothing else.
     *
     * @return int How many mails were removed.
     */
    public function deleteQueuedBefore(DateTimeImmutable $moment): int;
}
