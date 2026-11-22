<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Infrastructure\Mail;

use ArrayObject;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Mail\Mail;
use SmokeDetector\Application\Mail\Mailer;
use SmokeDetector\Config\MailSettings;
use SmokeDetector\Config\Secret;
use SmokeDetector\Infrastructure\Mail\InMemoryMailer;
use SmokeDetector\Infrastructure\Mail\LazyMailer;
use SmokeDetector\Infrastructure\Mail\MailDeliveryFailed;
use SmokeDetector\Infrastructure\Mail\SymfonyMailer;
use SmokeDetector\Tests\Support\Fake\CapturingTransport;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * How a mail is handed to the SMTP transport, and when (HLD §4.5 "Mail"). No test talks to a real
 * mail server: the message that would be sent is inspected instead.
 */
final class SymfonyMailerTest extends TestCase
{
    public function test_the_message_has_sender_recipient_subject_and_both_parts(): void
    {
        $transport = new CapturingTransport();

        (new SymfonyMailer($transport, self::settings()))->send(self::mail());

        self::assertCount(1, $transport->messages);
        $message = $transport->messages[0];
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('no-reply@example.test', $message->getFrom()[0]->getAddress());
        self::assertSame('Smoke Detector Test', $message->getFrom()[0]->getName());
        self::assertCount(1, $message->getFrom());
        self::assertSame(['anna@example.org'], array_map(static fn (Address $address): string => $address->getAddress(), $message->getTo()));
        self::assertSame([], $message->getCc());
        self::assertSame([], $message->getBcc());
        self::assertSame('Bestätige deine E-Mail-Adresse 🔥', $message->getSubject());
        self::assertSame("Hey!\n\nhttps://app.example.test/verify-email?token=abc\n", $message->getTextBody());
        self::assertSame('<p>Hey!</p>', $message->getHtmlBody());
        self::assertSame('utf-8', $message->getTextCharset());
        self::assertSame('utf-8', $message->getHtmlCharset());
        self::assertSame([], $message->getAttachments());
    }

    public function test_the_message_is_marked_as_automatic_so_nobody_auto_replies_to_it(): void
    {
        $transport = new CapturingTransport();

        (new SymfonyMailer($transport, self::settings()))->send(self::mail());

        $headers = $transport->messages[0]->getHeaders();
        self::assertSame('auto-generated', $headers->get('Auto-Submitted')?->getBodyAsString());
        self::assertSame('All', $headers->get('X-Auto-Response-Suppress')?->getBodyAsString());
    }

    public function test_the_message_can_be_serialized_for_smtp_with_utf8_headers_encoded(): void
    {
        $transport = new CapturingTransport();

        (new SymfonyMailer($transport, self::settings()))->send(self::mail());

        $raw = $transport->messages[0]->toString();
        self::assertStringContainsString('To: anna@example.org', $raw);
        self::assertStringContainsString('From: Smoke Detector Test <no-reply@example.test>', $raw);
        self::assertStringContainsString('multipart/alternative', $raw);
        // The subject has non-ASCII characters: sent encoded, never as raw bytes in a header.
        self::assertStringContainsString('Subject: =?utf-8?Q?', $raw);
    }

    public function test_the_envelope_names_only_our_sender_and_the_one_recipient(): void
    {
        $transport = new CapturingTransport();

        (new SymfonyMailer($transport, self::settings()))->send(self::mail());

        self::assertCount(1, $transport->envelopes);
        self::assertSame('no-reply@example.test', $transport->envelopes[0]->getSender()->getAddress());
        self::assertSame(
            ['anna@example.org'],
            array_map(static fn (Address $address): string => $address->getAddress(), $transport->envelopes[0]->getRecipients()),
        );
    }

    public function test_a_transport_failure_is_reported_without_the_mail_or_the_server_answer(): void
    {
        $transport = new CapturingTransport(new TransportException('550 <anna@example.org> rejected, password=hunter2'));

        try {
            (new SymfonyMailer($transport, self::settings()))->send(self::mail());
            self::fail('Expected the failure to be reported.');
        } catch (MailDeliveryFailed $failure) {
            self::assertSame('The mail "confirm_email" could not be handed to the mail server (TransportException).', $failure->getMessage());
            // Neither the message nor the chain behind it carries the address or the server's words.
            self::assertNull($failure->getPrevious());
        }
    }

    public function test_the_null_transport_of_development_accepts_the_message(): void
    {
        (new SymfonyMailer(new NullTransport(), self::settings()))->send(self::mail());

        $this->addToAssertionCount(1);
    }

    public function test_the_real_mailer_is_built_when_the_first_mail_is_sent_and_only_once(): void
    {
        // A web request and most console commands never send a mail: they must not need the mail
        // settings to be right, or even read them.
        $built = new ArrayObject();
        $inner = new InMemoryMailer();
        $mailer = new LazyMailer(static function () use ($built, $inner): Mailer {
            $built->append('built');

            return $inner;
        });
        self::assertCount(0, $built);

        $mailer->send(self::mail());
        $mailer->send(self::mail('bob@example.org'));

        self::assertCount(1, $built);
        self::assertSame(['anna@example.org', 'bob@example.org'], array_map(static fn (Mail $mail): string => $mail->to->value, $inner->sent()));
    }

    public function test_a_mailer_that_cannot_be_built_fails_the_mail_and_is_built_again_for_the_next(): void
    {
        $tries = new ArrayObject();
        $inner = new InMemoryMailer();
        $mailer = new LazyMailer(static function () use ($tries, $inner): Mailer {
            $tries->append('try');

            return count($tries) === 1 ? throw new InvalidArgumentException('Bad DSN smtp://user:hunter2@example.test') : $inner;
        });

        try {
            $mailer->send(self::mail());
            self::fail('Expected the failure to be passed on.');
        } catch (InvalidArgumentException) {
            // The sender of the outbox counts it as a failed try and logs its class only.
        }
        $mailer->send(self::mail('bob@example.org'));

        self::assertCount(2, $tries);
        self::assertCount(1, $inner->sent());
    }

    public function test_a_failure_of_the_mail_server_is_passed_on_to_the_sender_of_the_outbox(): void
    {
        $mailer = new LazyMailer(static fn (): Mailer => new FailingFirstMailer());

        $this->expectException(RuntimeException::class);

        $mailer->send(self::mail());
    }

    private static function settings(): MailSettings
    {
        return new MailSettings(new Secret('null://null'), 'no-reply@example.test', 'Smoke Detector Test');
    }

    private static function mail(string $to = 'anna@example.org'): Mail
    {
        return new Mail(
            EmailAddress::fromInput($to),
            'confirm_email',
            'Bestätige deine E-Mail-Adresse 🔥',
            "Hey!\n\nhttps://app.example.test/verify-email?token=abc\n",
            '<p>Hey!</p>',
        );
    }
}
