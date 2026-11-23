<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Mail;

use SmokeDetector\Application\Mail\Mail;
use SmokeDetector\Application\Mail\Mailer;

/**
 * Keeps the mails instead of sending them: for tests (HLD §4.5 "Mail"). Like FrozenClock it is
 * deliberately mutable, so a test can hand it to the code under test and read it afterwards.
 */
final class InMemoryMailer implements Mailer
{
    /** @var list<Mail> In the order they were sent. */
    private array $sent = [];

    public function send(Mail $mail): void
    {
        $this->sent[] = $mail;
    }

    /**
     * @return list<Mail>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    /**
     * The mail that was sent last, or null when none was.
     */
    public function last(): ?Mail
    {
        return $this->sent === [] ? null : $this->sent[count($this->sent) - 1];
    }

    public function clear(): void
    {
        $this->sent = [];
    }
}
