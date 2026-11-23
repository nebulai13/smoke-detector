<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Competition\Duel;
use SmokeDetector\Application\Competition\Duels;
use SmokeDetector\Application\Competition\DuelStatus;
use SmokeDetector\Application\Day\ProgressRecords;
use SmokeDetector\Application\Time\LocalDate;

/**
 * The duels, in memory. Behaves like PdoDuels (both pass DuelRepositoryContract). Which duels can
 * be decided depends on the progress of both users, so it reads their progress as the SQL does.
 */
final class InMemoryDuels implements Duels, InMemoryState
{
    /** @var array<string, Duel> By id. */
    private array $duels = [];

    public function __construct(private readonly ProgressRecords $progress, ?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function find(string $id): ?Duel
    {
        return $this->duels[$id] ?? null;
    }

    public function save(Duel $duel): void
    {
        $this->duels[$duel->id] = $duel;
    }

    public function delete(string $id): void
    {
        unset($this->duels[$id]);
    }

    public function ofUser(UserId $userId, int $limit): array
    {
        $duels = $this->matching(static fn (Duel $duel): bool => self::isIn($duel, $userId));
        usort($duels, static fn (Duel $a, Duel $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

        return array_slice($duels, 0, $limit);
    }

    public function openOf(UserId $userId): array
    {
        $duels = $this->matching(
            static fn (Duel $duel): bool => self::isIn($duel, $userId) && in_array($duel->status, [DuelStatus::Proposed, DuelStatus::Active], true),
        );
        usort($duels, static fn (Duel $a, Duel $b): int => [$a->createdAt, $a->id] <=> [$b->createdAt, $b->id]);

        return $duels;
    }

    public function expired(DateTimeImmutable $now, int $limit): array
    {
        $duels = $this->matching(static fn (Duel $duel): bool => $duel->status === DuelStatus::Proposed && $duel->expiresAt <= $now);
        usort($duels, static fn (Duel $a, Duel $b): int => [$a->expiresAt, $a->id] <=> [$b->expiresAt, $b->id]);

        return array_slice($duels, 0, $limit);
    }

    public function decidable(int $limit): array
    {
        $duels = $this->matching(
            fn (Duel $duel): bool => $duel->status === DuelStatus::Active
                && $this->isThrough($duel->challengerId, $duel->challengerStart, $duel->periodDays)
                && $this->isThrough($duel->opponentId, $duel->opponentStart, $duel->periodDays),
        );
        usort($duels, static fn (Duel $a, Duel $b): int => [$a->answeredAt, $a->id] <=> [$b->answeredAt, $b->id]);

        return array_slice($duels, 0, $limit);
    }

    /**
     * @return list<Duel> Everything stored, in the order it was first saved: for the tests.
     */
    public function all(): array
    {
        return array_values($this->duels);
    }

    public function snapshot(): array
    {
        return $this->duels;
    }

    public function restore(array $snapshot): void
    {
        $this->duels = [];
        foreach ($snapshot as $id => $duel) {
            \assert($duel instanceof Duel);
            $this->duels[(string) $id] = $duel;
        }
    }

    /**
     * Is every day of this side final: is the last day of the duel before the next day to finalize?
     */
    private function isThrough(?UserId $userId, ?LocalDate $start, int $periodDays): bool
    {
        if ($userId === null || $start === null) {
            return false;
        }
        $progress = $this->progress->find($userId);

        return $progress !== null && $start->plusDays($periodDays - 1)->isBefore($progress->nextDay);
    }

    private static function isIn(Duel $duel, UserId $userId): bool
    {
        return ($duel->challengerId?->equals($userId) ?? false) || ($duel->opponentId?->equals($userId) ?? false);
    }

    /**
     * @param callable(Duel): bool $filter
     *
     * @return list<Duel>
     */
    private function matching(callable $filter): array
    {
        return array_values(array_filter($this->duels, $filter));
    }
}
