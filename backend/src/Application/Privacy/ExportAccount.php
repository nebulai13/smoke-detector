<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Privacy;

use Psr\Clock\ClockInterface;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\RateLimit\RateLimiter;
use SmokeDetector\Application\RateLimit\RateLimitExceeded;
use SmokeDetector\Application\TransactionRunner;

/**
 * GET /me/export (HLD §4.5 "Account", FR-ACC-07): everything stored about the user as one JSON
 * document, for GDPR Art. 15 (access) and Art. 20 (portability).
 *
 * The profile comes from the account, everything else from the tables of the UserDataRegistry.
 */
final readonly class ExportAccount
{
    /** Raised when the meaning of an existing member changes. New members do not raise it. */
    public const int FORMAT_VERSION = 1;

    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private RateLimiter $limiter,
        private UserRepository $users,
        private UserDataRegistry $registry,
    ) {
    }

    /**
     * @return array<string, mixed> The document, ready to be written as JSON (schema AccountExport).
     *
     * @throws RateLimitExceeded After 5 exports of the account in the current hour.
     * @throws AccountNotFound   When the account was deleted in the meantime.
     */
    public function execute(UserId $userId): array
    {
        $now = $this->clock->now();

        // Looked up before counting: nothing should be counted for an account that is gone. Account
        // deletion, which erases the counters of the account, has already run for it, so such a
        // counter would stay until the cleanup deletes it, two days later (PurgeExpiredData).
        if ($this->users->find($userId) === null) {
            throw new AccountNotFound();
        }
        $this->limiter->consume(PrivacyLimits::exportsPerAccount(), $userId->value, $now);

        // One transaction is one snapshot of the database: what changes while the export is being
        // read cannot end up half in the document.
        return $this->transactions->run(function () use ($userId, $now): array {
            $user = $this->users->find($userId) ?? throw new AccountNotFound();

            return [
                'formatVersion' => self::FORMAT_VERSION,
                'exportedAt' => ExportFormat::time($now),
                'profile' => self::profile($user),
            ] + $this->registry->export($user->id);
        });
    }

    /**
     * Every column of the account except the password hash.
     *
     * @return array<string, string|null>
     */
    private static function profile(User $user): array
    {
        return [
            'id' => $user->id->value,
            'email' => $user->email->value,
            'displayName' => $user->displayName->value,
            'avatar' => $user->avatar->key,
            'locale' => $user->locale->value,
            'timeZone' => $user->timeZone->name,
            'emailVerifiedAt' => ExportFormat::nullableTime($user->emailVerifiedAt),
            'createdAt' => ExportFormat::time($user->createdAt),
        ];
    }
}
