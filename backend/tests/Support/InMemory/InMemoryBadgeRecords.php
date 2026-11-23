<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Badge\AwardedBadge;
use SmokeDetector\Application\Badge\BadgeRecords;
use SmokeDetector\Domain\Badge\Badge;

/**
 * The badges of the users, in memory. Behaves like PdoBadgeRecords (both pass BadgeRecordsContract).
 */
final class InMemoryBadgeRecords implements BadgeRecords, InMemoryState
{
    /** @var array<string, AwardedBadge> By user id and badge key. */
    private array $badges = [];

    public function __construct(?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function add(AwardedBadge $badge): bool
    {
        $key = "{$badge->userId->value} {$badge->badge->value}";
        if (isset($this->badges[$key])) {
            // The first award stays.
            return false;
        }
        $this->badges[$key] = $badge;

        return true;
    }

    public function ofUser(UserId $userId): array
    {
        $held = [];
        foreach (Badge::cases() as $case) {
            $badge = $this->badges["{$userId->value} {$case->value}"] ?? null;
            if ($badge !== null) {
                $held[] = $badge;
            }
        }

        return $held;
    }

    public function heldBy(array $userIds): array
    {
        $held = [];
        foreach ($userIds as $userId) {
            $badges = array_map(static fn (AwardedBadge $badge): Badge => $badge->badge, $this->ofUser($userId));
            if ($badges !== []) {
                $held[$userId->value] = $badges;
            }
        }
        // By user id, as the database answers, whatever the order of the question.
        ksort($held);

        return $held;
    }

    /**
     * @return list<AwardedBadge> Everything stored, in the order it was stored: for the tests.
     */
    public function all(): array
    {
        return array_values($this->badges);
    }

    public function snapshot(): array
    {
        return $this->badges;
    }

    public function restore(array $snapshot): void
    {
        $this->badges = [];
        foreach ($snapshot as $key => $badge) {
            \assert($badge instanceof AwardedBadge);
            $this->badges[(string) $key] = $badge;
        }
    }
}
