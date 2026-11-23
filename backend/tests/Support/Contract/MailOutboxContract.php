<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Contract;

use SmokeDetector\Application\Account\Locale;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Mail\MailKind;
use SmokeDetector\Application\Mail\MailOutbox;
use SmokeDetector\Application\Mail\QueuedMail;
use SmokeDetector\Tests\Support\TestData;

/**
 * What every MailOutbox does. The in-memory one (used by the use case unit tests) and the PDO one
 * both run these tests.
 */
trait MailOutboxContract
{
    abstract protected function outbox(): MailOutbox;

    /**
     * @return list<QueuedMail> Every mail the outbox holds, by id.
     */
    abstract protected function storedMails(): array;

    /**
     * Makes sure the accounts exist that the rows of a test belong to.
     */
    abstract protected function givenUsers(UserId ...$ids): void;

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    abstract protected function transaction(callable $work): mixed;

    public function test_a_queued_mail_is_stored_as_it_was_given(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $first = TestData::queuedMail(10, 1, MailKind::ResetPassword, 'c2VhbGVkLXZhbHVlcy1vZi10aGUtZmlyc3Q=', '2026-11-17 11:58:03', '2026-11-17 12:03:03', 2, Locale::En, 'anna@example.org');
        $second = TestData::queuedMail(11, 2, MailKind::AlreadyRegistered);
        $third = TestData::queuedMail(12, 2, MailKind::ConfirmEmail);

        $this->queue($first, $second, $third);

        // Two mails of one kind for one account are two rows: each request has its own mail.
        $this->queue(TestData::queuedMail(13, 2, MailKind::ConfirmEmail));
        self::assertEquals([$first, $second, $third, TestData::queuedMail(13, 2, MailKind::ConfirmEmail)], $this->storedMails());
    }

    public function test_only_mails_that_are_due_are_taken(): void
    {
        $this->givenUsers(TestData::userId(1));
        $due = TestData::queuedMail(10, createdAt: '2026-11-17 11:00:00', notBefore: '2026-11-17 11:59:59');
        $dueNow = TestData::queuedMail(11, createdAt: '2026-11-17 11:00:01', notBefore: '2026-11-17 12:00:00');
        $later = TestData::queuedMail(12, createdAt: '2026-11-17 11:00:02', notBefore: '2026-11-17 12:00:01');
        $this->queue($later, $dueNow, $due);

        $taken = $this->take('2026-11-17 12:00:00', 10, '2026-11-17 12:10:00');

        // As they were stored: the caller needs their own not_before to put one back.
        self::assertEquals([$due, $dueNow], $taken);
    }

    public function test_due_mails_are_taken_oldest_first_and_no_more_than_asked_for(): void
    {
        $this->givenUsers(TestData::userId(1));
        // Oldest by the time they were queued, not by when they are due or by the order of the rows.
        $newest = TestData::queuedMail(10, createdAt: '2026-11-17 11:30:00', notBefore: '2026-11-17 11:30:00');
        $oldest = TestData::queuedMail(13, createdAt: '2026-11-17 11:10:00', notBefore: '2026-11-17 11:50:00');
        $middleFirstId = TestData::queuedMail(11, createdAt: '2026-11-17 11:20:00', notBefore: '2026-11-17 11:40:00');
        $middleSecondId = TestData::queuedMail(12, createdAt: '2026-11-17 11:20:00', notBefore: '2026-11-17 11:20:00');
        $this->queue($newest, $middleSecondId, $oldest, $middleFirstId);

        self::assertEquals([$oldest, $middleFirstId, $middleSecondId], $this->take('2026-11-17 12:00:00', 3, '2026-11-17 12:10:00'));
        // The next batch goes on where the first ended.
        self::assertEquals([$newest], $this->take('2026-11-17 12:00:00', 3, '2026-11-17 12:10:00'));
        self::assertSame([], $this->take('2026-11-17 12:00:00', 3, '2026-11-17 12:10:00'));
    }

    public function test_a_taken_mail_is_set_aside_until_the_given_moment_and_otherwise_unchanged(): void
    {
        $this->givenUsers(TestData::userId(1));
        $mail = TestData::queuedMail(10, createdAt: '2026-11-17 11:00:00', notBefore: '2026-11-17 11:05:00', attempts: 3);
        $untouched = TestData::queuedMail(11, createdAt: '2026-11-17 11:00:00', notBefore: '2026-11-17 12:30:00');
        $this->queue($mail, $untouched);

        $this->take('2026-11-17 12:00:00', 10, '2026-11-17 12:10:00');

        self::assertEquals(
            [TestData::queuedMail(10, createdAt: '2026-11-17 11:00:00', notBefore: '2026-11-17 12:10:00', attempts: 3), $untouched],
            $this->storedMails(),
        );
        // Not due for anybody until then, and due again from then on.
        self::assertSame([], $this->take('2026-11-17 12:09:59', 10, '2026-11-17 12:20:00'));
        self::assertCount(1, $this->take('2026-11-17 12:10:00', 10, '2026-11-17 12:20:00'));
    }

    public function test_a_mail_is_rescheduled_with_its_failed_tries_and_alone(): void
    {
        $this->givenUsers(TestData::userId(1));
        $this->queue(TestData::queuedMail(10), TestData::queuedMail(11));

        $this->outbox()->reschedule(TestData::uuid(10), 4, TestData::time('2026-11-17 13:00:07'));
        // A mail that is gone (its account was deleted meanwhile) is no error.
        $this->outbox()->reschedule(TestData::uuid(99), 1, TestData::time('2026-11-17 13:00:00'));

        self::assertEquals(
            [TestData::queuedMail(10, notBefore: '2026-11-17 13:00:07', attempts: 4), TestData::queuedMail(11)],
            $this->storedMails(),
        );
    }

    public function test_mails_queued_before_a_moment_are_deleted_whatever_their_kind_and_state(): void
    {
        $this->givenUsers(TestData::userId(1), TestData::userId(2));
        $older = TestData::queuedMail(10, 1, MailKind::ResetPassword, createdAt: '2026-11-16 11:59:59', notBefore: '2026-11-17 13:00:00', attempts: 7);
        $olderToo = TestData::queuedMail(11, 2, MailKind::AlreadyRegistered, createdAt: '2026-11-15 08:00:00');
        $atTheMoment = TestData::queuedMail(12, 1, MailKind::ConfirmEmail, createdAt: '2026-11-16 12:00:00');
        $younger = TestData::queuedMail(13, 2, MailKind::ConfirmEmail, createdAt: '2026-11-16 12:00:01');
        $this->queue($older, $olderToo, $atTheMoment, $younger);

        $deleted = $this->transaction(fn (): int => $this->outbox()->deleteQueuedBefore(TestData::time('2026-11-16 12:00:00')));

        self::assertSame(2, $deleted);
        self::assertEquals([$atTheMoment, $younger], $this->storedMails());
        // Nothing left to delete: it can run as often as wanted.
        self::assertSame(0, $this->transaction(fn (): int => $this->outbox()->deleteQueuedBefore(TestData::time('2026-11-16 12:00:00'))));
    }

    public function test_a_mail_is_deleted_alone(): void
    {
        $this->givenUsers(TestData::userId(1));
        $this->queue(TestData::queuedMail(10), TestData::queuedMail(11));

        $this->outbox()->delete(TestData::uuid(10));
        $this->outbox()->delete(TestData::uuid(99));

        self::assertEquals([TestData::queuedMail(11)], $this->storedMails());
    }

    private function queue(QueuedMail ...$mails): void
    {
        $this->transaction(function () use ($mails): void {
            foreach ($mails as $mail) {
                $this->outbox()->add($mail);
            }
        });
    }

    /**
     * @return list<QueuedMail>
     */
    private function take(string $now, int $limit, string $leaseUntil): array
    {
        return $this->transaction(fn (): array => $this->outbox()->claimDue(TestData::time($now), $limit, TestData::time($leaseUntil)));
    }
}
