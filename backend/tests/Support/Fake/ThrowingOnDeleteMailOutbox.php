<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use DateTimeImmutable;
use SmokeDetector\Application\Mail\MailOutbox;
use SmokeDetector\Application\Mail\QueuedMail;
use Throwable;

/**
 * An outbox whose delete() fails with the given failure, and which otherwise is the outbox it wraps.
 */
final readonly class ThrowingOnDeleteMailOutbox implements MailOutbox
{
    public function __construct(private MailOutbox $inner, private Throwable $failure)
    {
    }

    public function add(QueuedMail $mail): void
    {
        $this->inner->add($mail);
    }

    public function claimDue(DateTimeImmutable $now, int $limit, DateTimeImmutable $leaseUntil): array
    {
        return $this->inner->claimDue($now, $limit, $leaseUntil);
    }

    public function reschedule(string $id, int $attempts, DateTimeImmutable $notBefore): void
    {
        $this->inner->reschedule($id, $attempts, $notBefore);
    }

    public function delete(string $id): void
    {
        throw $this->failure;
    }

    public function deleteQueuedBefore(DateTimeImmutable $moment): int
    {
        return $this->inner->deleteQueuedBefore($moment);
    }
}
