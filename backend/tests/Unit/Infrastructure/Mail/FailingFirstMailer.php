<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Infrastructure\Mail;

use RuntimeException;
use SmokeDetector\Application\Mail\Mail;
use SmokeDetector\Application\Mail\Mailer;

/**
 * A mailer whose first send fails, with a message that contains what must not be logged.
 */
final class FailingFirstMailer implements Mailer
{
    /** @var list<string> */
    public array $delivered = [];

    private bool $failed = false;

    public function send(Mail $mail): void
    {
        if (!$this->failed) {
            $this->failed = true;

            throw new RuntimeException("Cannot send to {$mail->to->value}: {$mail->text}");
        }

        $this->delivered[] = $mail->to->value;
    }
}
