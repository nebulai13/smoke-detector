<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use DateTimeImmutable;
use SmokeDetector\Application\Mail\MailOutbox;
use SmokeDetector\Application\Mail\QueuedMail;

/**
 * The real outbox, with a count of the mails that were queued: the test harness lets the cron job
 * run after a request that queued one, and leaves the outbox alone after every other request.
 */
final class CountingMailOutbox implements MailOutbox
{
    /** How many mails were queued so far (whether or not their transaction was committed). */
    public int $added = 0;

    public function __construct(private readonly MailOutbox $outbox)
    {
    }

    public function add(QueuedMail $mail): void
    {
        $this->outbox->add($mail);
        $this->added++;
    }

    public function claimDue(DateTimeImmutable $now, int $limit, DateTimeImmutable $leaseUntil): array
    {
        return $this->outbox->claimDue($now, $limit, $leaseUntil);
    }

    public function reschedule(string $id, int $attempts, DateTimeImmutable $notBefore): void
    {
        $this->outbox->reschedule($id, $attempts, $notBefore);
    }

    public function delete(string $id): void
    {
        $this->outbox->delete($id);
    }

    public function deleteQueuedBefore(DateTimeImmutable $moment): int
    {
        return $this->outbox->deleteQueuedBefore($moment);
    }
}
