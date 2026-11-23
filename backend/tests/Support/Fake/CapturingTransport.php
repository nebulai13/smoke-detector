<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use LogicException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Stands in for the SMTP transport: keeps the messages it is given, or fails as told.
 */
final class CapturingTransport implements TransportInterface
{
    /** @var list<Email> */
    public array $messages = [];

    /** @var list<Envelope> */
    public array $envelopes = [];

    public function __construct(private readonly ?TransportExceptionInterface $failure = null)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if (!$message instanceof Email) {
            throw new LogicException('Expected an Email.');
        }

        $this->messages[] = $message;
        $this->envelopes[] = $envelope ?? Envelope::create($message);

        return null;
    }

    public function __toString(): string
    {
        return 'capturing://';
    }
}
