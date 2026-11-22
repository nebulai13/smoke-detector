<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use SensitiveParameter;
use SmokeDetector\Application\Account\EmailAddress;
use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Mail\AccountMails;
use SmokeDetector\Application\Mail\Mail;
use SmokeDetector\Application\Mail\MailTemplates;
use SmokeDetector\Application\Mail\SendQueuedMails;
use SmokeDetector\Infrastructure\Clock\FrozenClock;
use SmokeDetector\Infrastructure\Mail\InMemoryMailer;
use SmokeDetector\Tests\Support\InMemory\InMemoryMailOutbox;
use SmokeDetector\Tests\Support\InMemory\InMemoryTransactionRunner;
use SmokeDetector\Tests\Support\TestAccounts;

/**
 * The mails around an account as they arrive: queued by the real AccountMails, then made from the
 * given templates and handed over by the real SendQueuedMails. For tests about what a mail says.
 */
final readonly class DeliveredAccountMails
{
    public function __construct(
        private MailTemplates $templates,
        private string $appUrl,
    ) {
    }

    public function confirmEmail(EmailAddress $to, Locale $locale, #[SensitiveParameter] string $token): Mail
    {
        return $this->deliver($to, $locale, static fn (AccountMails $mails, User $user, DateTimeImmutable $now) => $mails->confirmEmail($user, $token, $now));
    }

    public function alreadyRegistered(EmailAddress $to, Locale $locale): Mail
    {
        return $this->deliver($to, $locale, static fn (AccountMails $mails, User $user, DateTimeImmutable $now) => $mails->alreadyRegistered($user, $now));
    }

    public function resetPassword(EmailAddress $to, Locale $locale, #[SensitiveParameter] string $token): Mail
    {
        return $this->deliver($to, $locale, static fn (AccountMails $mails, User $user, DateTimeImmutable $now) => $mails->resetPassword($user, $token, $now));
    }

    /**
     * @param Closure(AccountMails, User, DateTimeImmutable): void $queue
     */
    private function deliver(EmailAddress $to, Locale $locale, Closure $queue): Mail
    {
        $clock = FrozenClock::at('2026-11-17 12:00:00');
        $transactions = new InMemoryTransactionRunner();
        $outbox = new InMemoryMailOutbox($transactions);
        $cipher = new FakeMailValuesCipher();
        $mailer = new InMemoryMailer();
        $logger = new RecordingLogger();
        $ids = new SequentialIdGenerator();
        $user = TestAccounts::user($ids->next(), $to->value, $clock->now(), $clock->now(), $locale);

        $transactions->run(fn () => $queue(new AccountMails($outbox, $cipher, $ids, $this->appUrl), $user, $clock->now()));
        (new SendQueuedMails($transactions, $clock, $outbox, $cipher, $this->templates, $mailer, $logger))->execute();

        $mail = $mailer->last();
        Assert::assertNotNull($mail, 'The mail was not sent: ' . $logger->text());
        Assert::assertCount(1, $mailer->sent());

        return $mail;
    }
}
