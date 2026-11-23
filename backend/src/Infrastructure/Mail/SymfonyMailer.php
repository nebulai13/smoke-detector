<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Mail;

use ReflectionClass;
use SmokeDetector\Application\Mail\Mail;
use SmokeDetector\Application\Mail\Mailer;
use SmokeDetector\Config\MailSettings;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends mail through symfony/mailer: over SMTP in production (the transport is built from the DSN
 * of the settings), into nothing with the "null://null" DSN of development.
 */
final readonly class SymfonyMailer implements Mailer
{
    public function __construct(
        private TransportInterface $transport,
        private MailSettings $settings,
    ) {
    }

    /**
     * @throws MailDeliveryFailed
     */
    public function send(Mail $mail): void
    {
        try {
            $this->transport->send($this->message($mail));
        } catch (TransportExceptionInterface $failure) {
            throw new MailDeliveryFailed($mail->template, (new ReflectionClass($failure))->getShortName());
        }
    }

    private function message(Mail $mail): Email
    {
        $message = (new Email())
            ->from(new Address($this->settings->fromAddress, $this->settings->fromName))
            ->to(new Address($mail->to->value))
            ->subject($mail->subject)
            ->text($mail->text)
            ->html($mail->html);

        // RFC 3834: tells vacation responders and ticket systems not to answer. The sender address
        // is not read by anyone.
        $message->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
        $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');

        return $message;
    }
}
