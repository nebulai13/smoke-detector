<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Mail;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SmokeDetector\Application\TransactionRunner;
use Throwable;

/**
 * Sends the mails of the outbox (HLD §4.5 "Mail"). The cron job runs it every minute as
 * `php bin/console mail:send`. It can run as often as wanted, and two runs at once never send the
 * same mail twice.
 *
 * How a mail travels:
 *
 * 1. Taken. A short transaction takes the due mails, oldest first, ten at a time, and sets them
 *    aside for ten minutes (MailOutbox::claimDue). The transaction ends before anything is sent:
 *    no row lock waits for a mail server.
 * 2. Sent, outside any transaction, and then deleted. The outbox keeps a mail, and with it the
 *    token of its link, only until the mail server has it.
 * 3. Not taken by the mail server: tried again after 1, 5 and 15 minutes, and then every 15
 *    minutes, for as long as the link of the mail works (24 hours for a confirmation, 1 hour for a
 *    reset, counted from when the mail was queued). There is no maximum number of tries: a mail
 *    is given up (deleted, one log line) only when its next try would come when its link has
 *    stopped working. A mail that is that old when it is taken is deleted without a try.
 * 4. Not the mail server's fault:
 *    - Values that cannot be opened (MailValuesUnreadable, after jwt.secret was changed): the mail
 *      is deleted at once. Waiting does not bring the old key back.
 *    - A mail that cannot be made (its template cannot be rendered, a release that is missing a
 *      file): kept as it is, with no try counted, and looked at again in 15 minutes, so that a
 *      repaired release sends it. The run counts it as "not made" and fails (MailSendCommand), so
 *      the cron job says so. Such a mail, too, is deleted once its link has stopped working.
 *
 * A run that dies between "sent" and "deleted" leaves the row behind, set aside. Ten minutes later
 * the mail is due again and goes out a second time. The same happens when the deletion itself
 * fails (a deadlock with the deletion of the account, a database that went away): the run logs a
 * warning and goes on. That is on purpose: a duplicate is harmless (the same link twice), a lost
 * mail is a person who cannot sign up. For the same reason nothing is counted as a failed try
 * before the mail server has refused: a crash costs time, not tries.
 *
 * A run takes no new mails once it has been at it for 40 seconds, and puts back what it has taken
 * and not started, so runs that start every minute do not pile up behind a slow mail server.
 */
final readonly class SendQueuedMails
{
    /** How long a run takes new mails. A mail that was started is finished, whatever the mail server's timeout is. */
    public const int DEFAULT_TIME_BUDGET_SECONDS = 40;

    /** How many mails are taken in one transaction. */
    public const int BATCH_SIZE = 10;

    /** How many batches a run takes at most: an end that does not depend on any clock. */
    public const int MAX_BATCHES = 50;

    /**
     * How long a taken mail is set aside: far longer than one mail can take. A run starts no mail
     * after its time budget (40 seconds), and the mail server gets 10 seconds for each answer
     * (ContainerFactory), so even a mail server that stops answering holds a started mail for a
     * minute or so. A mail that is still being sent must not come due for the next run.
     */
    public const int LEASE_SECONDS = 600;

    /** How long to wait after the first and the second failed try. */
    public const array BACKOFF_SECONDS = [60, 300];

    /**
     * How long to wait after the third failed try and every one after it, and before a mail that
     * could not be made is looked at again.
     */
    public const int REPEAT_SECONDS = 900;

    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private MailOutbox $outbox,
        private MailValuesCipher $cipher,
        private MailTemplates $templates,
        private Mailer $mailer,
        private LoggerInterface $logger,
        private int $timeBudgetSeconds = self::DEFAULT_TIME_BUDGET_SECONDS,
    ) {
    }

    public function execute(): MailSendReport
    {
        $deadline = $this->clock->now()->modify("+{$this->timeBudgetSeconds} seconds");
        $report = new MailSendReport(0, 0, 0);

        for ($batches = 0; $batches < self::MAX_BATCHES; $batches++) {
            $now = $this->clock->now();
            $batch = $now < $deadline ? $this->take($now) : [];
            if ($batch === []) {
                break;
            }

            foreach ($batch as $mail) {
                $now = $this->clock->now();
                if ($now >= $deadline) {
                    // Out of time. Back as it was, so the next run takes it at once: this is not
                    // a failed try.
                    $this->outbox->reschedule($mail->id, $mail->attempts, $mail->notBefore);

                    continue;
                }

                $report = $report->plus($this->send($mail, $now));
            }
        }

        return $report;
    }

    /**
     * @return list<QueuedMail>
     */
    private function take(DateTimeImmutable $now): array
    {
        $lease = self::LEASE_SECONDS;

        return $this->transactions->run(fn (): array => $this->outbox->claimDue(
            $now,
            self::BATCH_SIZE,
            $now->modify("+{$lease} seconds"),
        ));
    }

    private function send(QueuedMail $mail, DateTimeImmutable $now): MailSendReport
    {
        if ($now >= $mail->expiresAt()) {
            return $this->giveUp($mail, null);
        }

        try {
            $message = $this->templates->render(
                $mail->kind->value,
                $mail->locale,
                $mail->to,
                $this->cipher->open($mail->sealedValues),
            );
        } catch (MailValuesUnreadable $unreadable) {
            // The key the values were sealed with is gone. No later try can open them.
            return $this->giveUp($mail, $unreadable);
        } catch (Throwable $failure) {
            return $this->notMade($mail, $now, $failure);
        }

        try {
            $this->mailer->send($message);
        } catch (Throwable $failure) {
            return $this->failed($mail, $failure);
        }

        try {
            $this->outbox->delete($mail->id);
        } catch (RuntimeException $failure) {
            // The mail server has the mail, so it counts as sent. The row stays set aside and goes
            // out a second time once its lease is over (see the class comment). A LogicException
            // is a bug and is not caught.
            $this->logger->warning('A sent mail could not be deleted and may go out a second time.', [
                'template' => $mail->kind->value,
                'exception' => $failure::class,
            ]);
        }

        return new MailSendReport(1, 0, 0);
    }

    private function failed(QueuedMail $mail, Throwable $failure): MailSendReport
    {
        // Counted from now, not from when the try began: a mail server that does not answer has
        // taken its timeout already.
        $wait = self::BACKOFF_SECONDS[$mail->attempts] ?? self::REPEAT_SECONDS;
        $nextTry = $this->clock->now()->modify("+{$wait} seconds");
        if ($nextTry >= $mail->expiresAt()) {
            return $this->giveUp($mail, $failure);
        }

        $this->outbox->reschedule($mail->id, $mail->attempts + 1, $nextTry);

        return new MailSendReport(0, 1, 0);
    }

    /**
     * The mail could not be made, before any mail server was asked. The release is to blame, not
     * the mail server, so no try is counted; the mail waits for a repaired release, or until its
     * link has stopped working (send() then gives it up when it is taken).
     */
    private function notMade(QueuedMail $mail, DateTimeImmutable $now, Throwable $failure): MailSendReport
    {
        $wait = self::REPEAT_SECONDS;
        $this->outbox->reschedule($mail->id, $mail->attempts, $now->modify("+{$wait} seconds"));

        // As in giveUp(): the message of the failure may name the address.
        $this->logger->error('A mail could not be made and was kept.', [
            'template' => $mail->kind->value,
            'exception' => $failure::class,
        ]);

        return new MailSendReport(0, 0, 0, 1);
    }

    private function giveUp(QueuedMail $mail, ?Throwable $failure): MailSendReport
    {
        $this->outbox->delete($mail->id);

        // The template and the class of the failure, nothing else. The message of the failure may
        // hold the address or what the mail server said about it, and the mail holds the token.
        $this->logger->error('A mail could not be sent and was deleted.', [
            'template' => $mail->kind->value,
            'exception' => $failure === null ? null : $failure::class,
        ]);

        return new MailSendReport(0, 0, 1);
    }
}
