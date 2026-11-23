<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use DateTimeImmutable;
use LogicException;
use RuntimeException;
use SmokeDetector\Application\Mail\MailOutbox;
use SmokeDetector\Application\Mail\QueuedMail;

/**
 * The mail outbox, in memory. Behaves like PdoMailOutbox (both pass MailOutboxContract), except
 * that there is no second run to share the rows with.
 */
final class InMemoryMailOutbox implements MailOutbox, InMemoryState
{
    /** @var array<string, QueuedMail> By id, in the order they were added. */
    private array $mails = [];

    /** How many times add() fails before it works: a database that refuses the row. */
    public int $failNextAdds = 0;

    /** How many times delete() fails before it works: the process dies, or the database is gone. */
    public int $failNextDeletes = 0;

    /** How often due mails were asked for. */
    public int $claims = 0;

    public function __construct(private readonly ?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function add(QueuedMail $mail): void
    {
        // A mail is queued together with the token of its link, or not at all.
        $this->transactions?->assertActive('MailOutbox::add()');
        if ($this->failNextAdds > 0) {
            $this->failNextAdds--;

            throw new RuntimeException('The mail could not be queued.');
        }
        if (isset($this->mails[$mail->id])) {
            throw new LogicException('Duplicate mail id.');
        }

        $this->mails[$mail->id] = $mail;
    }

    public function claimDue(DateTimeImmutable $now, int $limit, DateTimeImmutable $leaseUntil): array
    {
        $this->transactions?->lock('mail_outbox', 'MailOutbox::claimDue()');
        $this->claims++;

        $due = array_values(array_filter($this->mails, static fn (QueuedMail $mail): bool => $mail->notBefore <= $now));
        usort($due, static fn (QueuedMail $a, QueuedMail $b): int => [$a->createdAt, $a->id] <=> [$b->createdAt, $b->id]);
        $claimed = array_slice($due, 0, $limit);
        foreach ($claimed as $mail) {
            $this->mails[$mail->id] = self::with($mail, $mail->attempts, $leaseUntil);
        }

        return $claimed;
    }

    public function reschedule(string $id, int $attempts, DateTimeImmutable $notBefore): void
    {
        $this->transactions?->assertNotActive('MailOutbox::reschedule()');
        if (isset($this->mails[$id])) {
            $this->mails[$id] = self::with($this->mails[$id], $attempts, $notBefore);
        }
    }

    public function delete(string $id): void
    {
        $this->transactions?->assertNotActive('MailOutbox::delete()');
        if ($this->failNextDeletes > 0) {
            $this->failNextDeletes--;

            throw new RuntimeException('The mail could not be deleted.');
        }
        unset($this->mails[$id]);
    }

    public function deleteQueuedBefore(DateTimeImmutable $moment): int
    {
        $this->transactions?->assertActive('MailOutbox::deleteQueuedBefore()');

        $old = array_filter($this->mails, static fn (QueuedMail $mail): bool => $mail->createdAt < $moment);
        $this->mails = array_diff_key($this->mails, $old);

        return count($old);
    }

    /**
     * @return list<QueuedMail> In the order they were added.
     */
    public function all(): array
    {
        return array_values($this->mails);
    }

    /**
     * Forgets every mail, as if all had been sent.
     */
    public function clear(): void
    {
        $this->mails = [];
    }

    public function snapshot(): array
    {
        return $this->mails;
    }

    public function restore(array $snapshot): void
    {
        $mails = [];
        foreach ($snapshot as $id => $mail) {
            \assert($mail instanceof QueuedMail);
            $mails[(string) $id] = $mail;
        }
        $this->mails = $mails;
    }

    private static function with(QueuedMail $mail, int $attempts, DateTimeImmutable $notBefore): QueuedMail
    {
        return new QueuedMail(
            $mail->id,
            $mail->userId,
            $mail->kind,
            $mail->locale,
            $mail->to,
            $mail->sealedValues,
            $mail->createdAt,
            $notBefore,
            $attempts,
        );
    }
}
