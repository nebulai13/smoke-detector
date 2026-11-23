<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Mail;

use Closure;
use SmokeDetector\Application\Mail\Mail;
use SmokeDetector\Application\Mail\Mailer;

/**
 * Builds the mailer that really sends only when the first mail is to be sent.
 *
 * Only the sender of the outbox sends mail (`mail:send`). Every web request and every other
 * console command never touches the mail settings this way: a mail DSN that is wrong must not keep
 * `migrate` or the cleanup from running. When the mailer cannot be built, the mail at hand fails
 * like a mail the server did not take (it is tried again later), and the next mail tries to build
 * the mailer again.
 *
 * Deliberately mutable: it remembers the mailer it built.
 */
final class LazyMailer implements Mailer
{
    private ?Mailer $mailer = null;

    /**
     * @param Closure(): Mailer $factory
     */
    public function __construct(private readonly Closure $factory)
    {
    }

    public function send(Mail $mail): void
    {
        $this->mailer ??= ($this->factory)();

        $this->mailer->send($mail);
    }
}
