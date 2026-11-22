<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Application;

use SmokeDetector\Application\Housekeeping\PurgeExpiredData;
use SmokeDetector\Application\Housekeeping\PurgeReport;
use SmokeDetector\Tests\Support\HttpTestCase;
use SmokeDetector\Tests\Support\TestAccounts;

/**
 * The cleanup use case with the real repositories and database, as the cron job runs it
 * (HLD §4.5 "Registration" step 7; FR-ACC-03).
 */
final class PurgeExpiredDataTest extends HttpTestCase
{
    public function test_FR_ACC_03_a_registration_that_is_not_confirmed_within_24_hours_is_deleted_with_its_consents_and_tokens(): void
    {
        $this->call('POST', '/auth/register', TestAccounts::registration(['email' => 'never-confirmed@example.org']));
        $this->clock()->advanceSeconds(3600);
        $this->call('POST', '/auth/register', TestAccounts::registration(['email' => 'confirmed@example.org']));
        $this->call('POST', '/auth/verify-email', self::confirmation(self::tokenIn($this->mailTo('confirmed@example.org'))));
        $this->call('POST', '/auth/register', TestAccounts::registration(['email' => 'still-in-time@example.org']));

        // 24 hours after the first registration its link has expired (and the used link of the
        // confirmed account goes too), but the registration itself is not due yet.
        $this->clock()->advanceSeconds(23 * 3600);
        self::assertEquals(new PurgeReport(0, 2, 0, 0, 0), $this->purge()->execute());
        self::assertSame(3, $this->sql()->value('SELECT COUNT(*) FROM users'));

        $this->clock()->advanceSeconds(1);
        $report = $this->purge()->execute();

        self::assertSame(1, $report->unconfirmedRegistrations);
        self::assertSame(
            ['confirmed@example.org', 'still-in-time@example.org'],
            $this->sql()->column('SELECT email FROM users ORDER BY email'),
        );
        // Everything of the deleted registration is gone, and nothing of the others.
        self::assertSame(6, $this->sql()->value('SELECT COUNT(*) FROM consents'));
        self::assertSame(
            0,
            $this->sql()->value(
                'SELECT (SELECT COUNT(*) FROM consents WHERE user_id NOT IN (SELECT id FROM users)) '
                . '+ (SELECT COUNT(*) FROM one_time_tokens WHERE user_id NOT IN (SELECT id FROM users))',
            ),
        );
        // The email is free again: registering it once more is a new registration.
        $this->mails()->clear();
        $this->fromClientAddress('198.51.100.9');
        $this->call('POST', '/auth/register', TestAccounts::registration(['email' => 'never-confirmed@example.org']));
        self::assertSame('confirm_email', $this->mailTo('never-confirmed@example.org')->template);
    }

    public function test_NFR_05_dead_tokens_and_old_rate_limit_counters_are_deleted_and_live_ones_stay(): void
    {
        $this->givenUser();
        $loggedOut = $this->logIn()->json()['refreshToken'] ?? null;
        $rotated = $this->logIn()->json()['refreshToken'] ?? null;
        $live = $this->call('POST', '/auth/refresh', ['refreshToken' => $rotated])->json()['refreshToken'] ?? null;
        self::assertIsString($live);
        self::assertIsString($rotated);
        $this->call('POST', '/auth/logout', ['refreshToken' => $loggedOut]);
        $this->call('POST', '/auth/password-reset/request', ['email' => 'anna@example.org']);
        // A failed login leaves three counters behind (client, pair and address), the reset request three more.
        $this->call('POST', '/auth/login', ['email' => 'anna@example.org', 'password' => 'not the password', 'refreshIn' => 'body']);

        // An hour later the reset link has expired. Counters are kept for two days.
        $this->clock()->advanceSeconds(3600);
        self::assertEquals(new PurgeReport(0, 1, 1, 0, 0), $this->purge()->execute());
        self::assertEquals(new PurgeReport(0, 0, 0, 0, 0), $this->purge()->execute());

        // The session that was logged out is gone. The live one is there, as one row with its current token.
        self::assertSame([hash('sha256', $live)], $this->sql()->column('SELECT token_hash FROM refresh_tokens'));
        // An older token of the live session is still noticed when it is presented again.
        self::assertProblem(401, 'invalid_refresh_token', $this->call('POST', '/auth/refresh', ['refreshToken' => $rotated]));
        self::assertSame(1, $this->sql()->value('SELECT COUNT(*) FROM refresh_tokens WHERE revoked_at IS NOT NULL'));

        $this->clock()->advanceSeconds(2 * 86_400);
        $report = $this->purge()->execute();
        self::assertSame(6, $report->rateLimitWindows);
        // The session that was ended because of the reuse is gone now too.
        self::assertSame(1, $report->refreshTokens);
        self::assertSame(0, $this->sql()->value('SELECT COUNT(*) FROM rate_limits'));
    }

    private function purge(): PurgeExpiredData
    {
        return $this->service(PurgeExpiredData::class);
    }
}
