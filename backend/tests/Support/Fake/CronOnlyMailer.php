<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use LogicException;
use SmokeDetector\Application\Mail\Mail;
use SmokeDetector\Application\Mail\Mailer;
use Throwable;

/**
 * The mail server of the HTTP tests. It takes mail only while the cron job runs (the test harness
 * says when). A mail that is handed over at any other time, which means by a web request, is
 * refused and noted, and HttpTestCase fails the test for it: a request must queue its mail and
 * never talk to a mail server (HLD §4.5 "Mail").
 *
 * Refusing alone would not do. The application answers 500 for the exception, or swallows it, and
 * a test that does not look at the answer would pass.
 */
final class CronOnlyMailer implements Mailer
{
    /** Set by the test harness for the time the sender of the outbox runs. */
    public bool $cronIsRunning = false;

    /** @var list<string> The templates of the mails a web request tried to send itself. */
    public array $sentByARequest = [];

    /** What the mail server answers every mail with, for tests about a mail server that is down. */
    public ?Throwable $failure = null;

    public function __construct(private readonly Mailer $inbox)
    {
    }

    public function send(Mail $mail): void
    {
        if (!$this->cronIsRunning) {
            $this->sentByARequest[] = $mail->template;

            throw new LogicException('A web request must not send mail. It queues the mail in the outbox.');
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->inbox->send($mail);
    }
}
