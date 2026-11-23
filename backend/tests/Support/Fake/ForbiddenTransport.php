<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use LogicException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Stands in for the SMTP transport in the HTTP tests, where nothing may ever reach it: no request
 * sends mail, and the cron job of the tests delivers into memory. Every call is counted, and
 * HttpTestCase fails the test for it.
 */
final class ForbiddenTransport implements TransportInterface
{
    public int $calls = 0;

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->calls++;

        throw new LogicException('The SMTP transport must not be touched in an HTTP test.');
    }

    public function __toString(): string
    {
        $this->calls++;

        return 'forbidden://';
    }
}
