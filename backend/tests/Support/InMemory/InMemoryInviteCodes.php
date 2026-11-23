<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Friends\InviteCode;
use SmokeDetector\Application\Friends\InviteCodes;

/**
 * The invite codes, in memory. Behaves like PdoInviteCodes (both pass FriendRepositoriesContract).
 */
final class InMemoryInviteCodes implements InviteCodes, InMemoryState
{
    /** @var array<string, array{InviteCode, DateTimeImmutable}> By user id: the code and when it was made. */
    private array $codes = [];

    public function __construct(?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function ofUser(UserId $userId): ?InviteCode
    {
        return $this->codes[$userId->value][0] ?? null;
    }

    public function owner(InviteCode $code): ?UserId
    {
        foreach ($this->codes as $userId => [$stored]) {
            if ($stored->value === $code->value) {
                return UserId::fromString($userId);
            }
        }

        return null;
    }

    public function assign(UserId $userId, InviteCode $code, DateTimeImmutable $at): bool
    {
        $owner = $this->owner($code);
        if ($owner !== null && !$owner->equals($userId)) {
            return false;
        }
        $this->codes[$userId->value] = [$code, $at];

        return true;
    }

    /** When the code of the user was made: for the tests. */
    public function madeAt(UserId $userId): ?DateTimeImmutable
    {
        return $this->codes[$userId->value][1] ?? null;
    }

    public function snapshot(): array
    {
        return $this->codes;
    }

    public function restore(array $snapshot): void
    {
        $this->codes = [];
        foreach ($snapshot as $userId => $entry) {
            \assert(is_array($entry) && $entry[0] instanceof InviteCode && $entry[1] instanceof DateTimeImmutable);
            $this->codes[(string) $userId] = [$entry[0], $entry[1]];
        }
    }
}
