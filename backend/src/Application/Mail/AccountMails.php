<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Mail;

use DateTimeImmutable;
use SensitiveParameter;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\IdGenerator;

/**
 * The mails around an account (HLD §4.5 "Mail"). The links point to the web app, which then calls
 * the API with the token in the request body.
 *
 * Every method queues its mail in the outbox and sends nothing: call it inside the transaction of
 * the use case, the one that stores the token of the link. The cron job sends the mail within a
 * minute or two (SendQueuedMails), in the language the account has at this moment.
 *
 * No mail contains anything the person who filled in the form typed (a name, for example): a
 * stranger can make us send these mails to any address, and must not be able to put words into them.
 */
final readonly class AccountMails
{
    public function __construct(
        private MailOutbox $outbox,
        private MailValuesCipher $cipher,
        private IdGenerator $ids,
        /** Where the web app lives (Settings::$appUrl), without a trailing slash. Only this value: mails need no secrets. */
        private string $appUrl,
    ) {
    }

    /**
     * For a new registration: the link that confirms the address (FR-ACC-01, FR-ACC-03).
     */
    public function confirmEmail(User $to, #[SensitiveParameter] string $token, DateTimeImmutable $now): void
    {
        $this->queue($to, MailKind::ConfirmEmail, [
            'confirmUrl' => $this->url('/verify-email?token=' . rawurlencode($token)),
        ], $now);
    }

    /**
     * For a registration with the address of an existing account: says so and points to login and
     * password reset (FR-ACC-01).
     */
    public function alreadyRegistered(User $to, DateTimeImmutable $now): void
    {
        $this->queue($to, MailKind::AlreadyRegistered, [
            'loginUrl' => $this->url('/login'),
            'resetUrl' => $this->url('/reset-password'),
        ], $now);
    }

    /**
     * The link to set a new password (FR-ACC-05).
     */
    public function resetPassword(User $to, #[SensitiveParameter] string $token, DateTimeImmutable $now): void
    {
        $this->queue($to, MailKind::ResetPassword, [
            'resetUrl' => $this->url('/reset-password?token=' . rawurlencode($token)),
        ], $now);
    }

    /**
     * @param array<string, string> $values
     */
    private function queue(User $to, MailKind $kind, #[SensitiveParameter] array $values, DateTimeImmutable $now): void
    {
        // Due at once: the next run of the sender takes it.
        $this->outbox->add(new QueuedMail(
            $this->ids->next(),
            $to->id,
            $kind,
            $to->locale,
            $to->email,
            $this->cipher->seal($values),
            $now,
            $now,
            0,
        ));
    }

    private function url(string $path): string
    {
        return $this->appUrl . $path;
    }
}
