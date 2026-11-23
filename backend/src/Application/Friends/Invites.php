<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Friends;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use RuntimeException;
use SmokeDetector\Application\Account\AccountNotFound;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;
use SmokeDetector\Application\TransactionRunner;

/**
 * GET and POST /me/invite (FR-FRND-01, HLD §4.7 "Invite code"): the personal invite code.
 *
 * The first look makes the code, and every later look shows the same one. A renewal replaces it,
 * and the old one leads nowhere at once. Both lock the account of the user and nothing else.
 */
final readonly class Invites
{
    /**
     * How many codes are tried before giving up. A code that is taken already is not given out
     * again; five of them in a row do not happen by chance (2^50 codes), so the generator is broken.
     */
    public const int MAX_TRIES = 5;

    public function __construct(
        private TransactionRunner $transactions,
        private ClockInterface $clock,
        private UserRepository $users,
        private InviteCodes $codes,
        private InviteCodeGenerator $generator,
    ) {
    }

    /**
     * @throws AccountNotFound  When the account was deleted in the meantime.
     * @throws RuntimeException When no unused code could be made.
     */
    public function codeOf(UserId $userId): InviteCode
    {
        $now = $this->clock->now();

        return $this->transactions->run(function () use ($userId, $now): InviteCode {
            // Locked first: two first looks at the same moment end with one code.
            $this->users->lock($userId) ?? throw new AccountNotFound();

            return $this->codes->ofUser($userId) ?? $this->assignNew($userId, $now);
        });
    }

    /**
     * @throws AccountNotFound  When the account was deleted in the meantime.
     * @throws RuntimeException When no unused code could be made. The code from before stays.
     */
    public function renew(UserId $userId): InviteCode
    {
        $now = $this->clock->now();

        return $this->transactions->run(function () use ($userId, $now): InviteCode {
            $this->users->lock($userId) ?? throw new AccountNotFound();

            return $this->assignNew($userId, $now);
        });
    }

    private function assignNew(UserId $userId, DateTimeImmutable $now): InviteCode
    {
        for ($try = 0; $try < self::MAX_TRIES; $try++) {
            $code = $this->generator->next();
            if ($this->codes->assign($userId, $code, $now)) {
                return $code;
            }
        }

        throw new RuntimeException('No unused invite code could be made.');
    }
}
