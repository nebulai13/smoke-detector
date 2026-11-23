<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use Closure;
use RuntimeException;
use SmokeDetector\Application\Mail\Mail;
use SmokeDetector\Application\Mail\Mailer;
use SmokeDetector\Infrastructure\Clock\FrozenClock;
use SmokeDetector\Tests\Support\InMemory\InMemoryTransactionRunner;
use Throwable;

/**
 * A mail server for the tests of the sender: takes time, fails for the addresses it is told to,
 * and refuses to be talked to inside a database transaction (a slow mail server would keep the
 * transaction's row locks for as long as it takes).
 */
final class ScriptedMailer implements Mailer
{
    /** @var list<Mail> What was accepted, in order. */
    public array $delivered = [];

    /** @var list<string> The address of every mail that was handed over, accepted or not, in order. */
    public array $attempts = [];

    /** @var array<string, Throwable|null> Address => what sending to it throws (null: a RuntimeException that names the address and the text). */
    public array $failures = [];

    /** How long the mail server takes for one mail, accepted or not. */
    public int $secondsPerMail = 0;

    /** @var (Closure(Mail): void)|null Called while a mail is being sent, before the time passes. */
    public ?Closure $whileSending = null;

    public function __construct(
        private readonly FrozenClock $clock,
        private readonly InMemoryTransactionRunner $transactions,
    ) {
    }

    public function send(Mail $mail): void
    {
        $this->transactions->assertNotActive('Mailer::send()');

        $this->attempts[] = $mail->to->value;
        if ($this->whileSending !== null) {
            ($this->whileSending)($mail);
        }
        $this->clock->advanceSeconds($this->secondsPerMail);

        if (array_key_exists($mail->to->value, $this->failures)) {
            // Like a real failure: the message holds what must never reach a log.
            throw $this->failures[$mail->to->value] ?? new RuntimeException("Cannot send to {$mail->to->value}: {$mail->text}");
        }

        $this->delivered[] = $mail;
    }

    /**
     * @return list<string> The addresses of the mails that were accepted, in order.
     */
    public function deliveredTo(): array
    {
        return array_map(static fn (Mail $mail): string => $mail->to->value, $this->delivered);
    }
}
