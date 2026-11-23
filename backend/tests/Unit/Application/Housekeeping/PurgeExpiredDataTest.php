<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Application\Housekeeping;

use DateTimeImmutable;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Auth\OneTimeToken;
use SmokeDetector\Application\Auth\RefreshToken;
use SmokeDetector\Application\Auth\TokenPurpose;
use SmokeDetector\Application\Housekeeping\PurgeExpiredData;
use SmokeDetector\Application\Housekeeping\PurgeReport;
use SmokeDetector\Application\Mail\MailKind;
use SmokeDetector\Tests\Support\TestData;
use SmokeDetector\Tests\Unit\Application\Auth\AuthUseCaseTestCase;

/**
 * The cleanup the cron job runs (HLD §4.5 "Registration" step 7, §5.2).
 */
final class PurgeExpiredDataTest extends AuthUseCaseTestCase
{
    public function test_FR_ACC_03_registrations_unconfirmed_for_more_than_24_hours_are_deleted(): void
    {
        $tooOld = $this->givenUnconfirmed('old@example.org', '2026-11-16 11:59:59');
        $exactly24Hours = $this->givenUnconfirmed('edge@example.org', '2026-11-16 12:00:00');
        $fresh = $this->givenUnconfirmed('fresh@example.org', '2026-11-17 11:00:00');
        // Confirmed accounts are never deleted here, however old.
        $confirmed = $this->givenUser('confirmed@example.org');

        $report = $this->purge()->execute();

        self::assertSame(1, $report->unconfirmedRegistrations);
        self::assertNull($this->users->find($tooOld->id));
        self::assertEquals([$exactly24Hours, $fresh, $confirmed], $this->users->all());
    }

    public function test_FR_ACC_03_a_registration_survives_exactly_until_its_24_hours_are_over(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);

        $this->clock->advanceSeconds(86_400);
        self::assertSame(0, $this->purge()->execute()->unconfirmedRegistrations);
        self::assertCount(1, $this->users->all());

        $this->clock->advanceSeconds(1);
        self::assertSame(1, $this->purge()->execute()->unconfirmedRegistrations);
        self::assertSame([], $this->users->all());
    }

    public function test_FR_ACC_03_a_registration_that_was_confirmed_in_time_stays(): void
    {
        $this->registerUser()->execute(self::registration(), self::CLIENT);
        $this->confirm('token-1');
        $this->clock->advanceSeconds(30 * 86_400);

        self::assertSame(0, $this->purge()->execute()->unconfirmedRegistrations);
        self::assertCount(1, $this->users->all());
    }

    public function test_NFR_05_expired_and_used_one_time_tokens_are_deleted(): void
    {
        // A user has one token per purpose, so each case has its own user.
        $this->givenOneTimeToken($this->givenUser('a@example.org'), 'expired a second ago', expiresAt: '2026-11-17 11:59:59');
        $this->givenOneTimeToken($this->givenUser('b@example.org'), 'expires now', expiresAt: '2026-11-17 12:00:00');
        $this->givenOneTimeToken($this->givenUser('c@example.org'), 'used', expiresAt: '2026-11-17 13:00:00', usedAt: '2026-11-17 11:00:00');
        $this->givenOneTimeToken($this->givenUser('d@example.org'), 'live', expiresAt: '2026-11-17 12:00:01');

        $report = $this->purge()->execute();

        self::assertSame(3, $report->oneTimeTokens);
        self::assertSame([hash('sha256', 'live')], array_map(static fn (OneTimeToken $token): string => $token->tokenHash, $this->oneTimeTokens->all()));
    }

    public function test_NFR_05_expired_and_revoked_sessions_are_deleted_and_live_ones_stay(): void
    {
        $user = $this->givenUser();
        $this->givenSession($user, 'expired a second ago', expiresAt: '2026-11-17 11:59:59');
        $this->givenSession($user, 'expires now', expiresAt: '2026-11-17 12:00:00');
        $this->givenSession($user, 'revoked', expiresAt: '2027-01-01 00:00:00', revokedAt: '2026-11-17 11:00:00');
        $this->givenSession($user, 'refreshed', expiresAt: '2027-01-01 00:00:00', rotatedAt: '2026-11-17 11:00:00');
        $this->givenSession($user, 'live', expiresAt: '2026-11-17 12:00:01');

        $report = $this->purge()->execute();

        self::assertSame(3, $report->refreshTokens);
        self::assertSame(
            [hash('sha256', 'refreshed'), hash('sha256', 'live')],
            array_map(static fn (RefreshToken $session): string => $session->keyHash, $this->refreshTokens->all()),
        );
    }

    public function test_NFR_05_rate_limit_counters_older_than_two_days_are_deleted(): void
    {
        $this->counters->increment('a', new DateTimeImmutable('2026-11-15 11:59:59 UTC'));
        $this->counters->increment('b', new DateTimeImmutable('2026-11-15 12:00:00 UTC'));
        $this->counters->increment('c', new DateTimeImmutable('2026-11-17 11:45:00 UTC'));

        $report = $this->purge()->execute();

        self::assertSame(1, $report->rateLimitWindows);
        self::assertSame(['b', 'c'], array_keys($this->counters->all()));
    }

    public function test_with_nothing_to_delete_the_report_is_all_zeros_and_it_can_run_again(): void
    {
        $this->givenUser();

        self::assertEquals(new PurgeReport(0, 0, 0, 0, 0), $this->purge()->execute());
        self::assertEquals(new PurgeReport(0, 0, 0, 0, 0), $this->purge()->execute());
        // Four kinds of rows, each in a transaction of its own, in two runs.
        self::assertSame(8, $this->transactions->commits);
    }

    public function test_each_kind_of_row_is_deleted_in_a_transaction_of_its_own(): void
    {
        // The cleanup must never hold locks in two tables at once. Account deletion takes a user's
        // sessions and then the mail link tokens. A cleanup that held tokens while it waited for
        // sessions would wait for that request, and that request for the cleanup (a deadlock).
        // Nothing is lost by it: each deletion stands for itself, and the next run does what is left.
        $user = $this->givenUser();
        $this->givenUnconfirmed('old@example.org', '2026-11-10 00:00:00');
        $this->givenOneTimeToken($user, 'a', expiresAt: '2026-11-10 00:00:00');
        $this->givenSession($user, 'b', expiresAt: '2026-11-10 00:00:00');

        self::assertEquals(new PurgeReport(1, 1, 1, 0, 0), $this->purge()->execute());

        self::assertSame(4, $this->transactions->commits);
        self::assertSame(0, $this->transactions->rollbacks);
    }

    public function test_the_report_counts_each_kind_separately(): void
    {
        $user = $this->givenUser();
        $this->givenUnconfirmed('old1@example.org', '2026-11-10 00:00:00');
        $this->givenUnconfirmed('old2@example.org', '2026-11-10 00:00:00');
        $this->givenUnconfirmed('old3@example.org', '2026-11-10 00:00:00');
        $this->givenUnconfirmed('old4@example.org', '2026-11-10 00:00:00');
        $this->givenOneTimeToken($user, 'a', expiresAt: '2026-11-10 00:00:00');
        $this->givenOneTimeToken($this->givenUser('b@example.org'), 'b', expiresAt: '2026-11-10 00:00:00');
        $this->givenOneTimeToken($this->givenUser('c@example.org'), 'c', expiresAt: '2026-11-10 00:00:00');
        $this->givenSession($user, 'd', expiresAt: '2026-11-10 00:00:00');
        $this->givenSession($user, 'e', expiresAt: '2026-11-10 00:00:00');
        $this->counters->increment('f', new DateTimeImmutable('2026-11-10 00:00:00 UTC'));

        self::assertEquals(new PurgeReport(4, 3, 2, 1, 0), $this->purge()->execute());
    }

    public function test_queued_mails_older_than_any_link_can_be_valid_are_deleted_whatever_their_template(): void
    {
        // The sender only takes mails whose template the running release knows. After a release
        // was rolled back, the mails of the newer one would wait for ever, address and all.
        $user = $this->givenUser();
        $old = TestData::queuedMail(10, 1, MailKind::ResetPassword, createdAt: '2026-11-16 11:59:59');
        $exactly24Hours = TestData::queuedMail(11, 1, MailKind::ConfirmEmail, createdAt: '2026-11-16 12:00:00');
        $fresh = TestData::queuedMail(12, 1, MailKind::ConfirmEmail, createdAt: '2026-11-17 11:59:00');
        $this->transactions->run(function () use ($old, $exactly24Hours, $fresh): void {
            $this->outbox->add($old);
            $this->outbox->add($exactly24Hours);
            $this->outbox->add($fresh);
        });

        $report = $this->purge()->execute();

        self::assertSame(1, $report->queuedMails);
        self::assertEquals([$exactly24Hours, $fresh], $this->outbox->all());
        // The account itself is confirmed and stays.
        self::assertNotNull($this->users->find($user->id));
        // 24 hours is the longest a link is valid: the cleanup and the sender use the same number.
        self::assertSame(MailKind::ConfirmEmail->lifetimeSeconds(), PurgeExpiredData::QUEUED_MAIL_LIFETIME_SECONDS);
        self::assertSame(86_400, PurgeExpiredData::QUEUED_MAIL_LIFETIME_SECONDS);
    }

    private function purge(): PurgeExpiredData
    {
        return new PurgeExpiredData(
            $this->transactions,
            $this->clock,
            $this->users,
            $this->oneTimeTokens,
            $this->refreshTokens,
            $this->counters,
            $this->outbox,
        );
    }

    private function givenUnconfirmed(string $email, string $createdAt): User
    {
        $user = $this->givenUser($email, verified: false);
        $stored = new User(
            $user->id,
            $user->email,
            $user->passwordHash,
            $user->displayName,
            $user->avatar,
            $user->locale,
            $user->timeZone,
            null,
            new DateTimeImmutable($createdAt . ' UTC'),
        );
        $this->users->update($stored);

        return $stored;
    }

    private function givenOneTimeToken(User $user, string $token, string $expiresAt, ?string $usedAt = null): void
    {
        $this->oneTimeTokens->put(new OneTimeToken(
            $this->ids->next(),
            $user->id,
            TokenPurpose::ResetPassword,
            hash('sha256', $token),
            new DateTimeImmutable($expiresAt . ' UTC'),
            $usedAt === null ? null : new DateTimeImmutable($usedAt . ' UTC'),
        ));
    }

    private function givenSession(User $user, string $key, string $expiresAt, ?string $rotatedAt = null, ?string $revokedAt = null): void
    {
        $this->refreshTokens->add(new RefreshToken(
            $this->ids->next(),
            $user->id,
            hash('sha256', $key),
            hash('sha256', "{$key}.secret"),
            null,
            new DateTimeImmutable('2026-11-01 00:00:00 UTC'),
            $rotatedAt === null ? null : new DateTimeImmutable($rotatedAt . ' UTC'),
            new DateTimeImmutable($expiresAt . ' UTC'),
            $revokedAt === null ? null : new DateTimeImmutable($revokedAt . ' UTC'),
        ));
    }
}
