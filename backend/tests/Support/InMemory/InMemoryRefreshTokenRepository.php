<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use DateTimeImmutable;
use LogicException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Auth\RefreshToken;
use SmokeDetector\Application\Auth\RefreshTokenRepository;

/**
 * The sessions with their refresh tokens, in memory. Behaves like PdoRefreshTokenRepository (both
 * pass RefreshTokenRepositoryContract).
 */
final class InMemoryRefreshTokenRepository implements RefreshTokenRepository, InMemoryState
{
    /** @var array<string, RefreshToken> By id. */
    private array $sessions = [];

    public function __construct(private readonly ?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function add(RefreshToken $session): void
    {
        foreach ($this->sessions as $existing) {
            if ($existing->id === $session->id || $existing->keyHash === $session->keyHash || $existing->tokenHash === $session->tokenHash) {
                throw new LogicException('Duplicate session.');
            }
        }

        $this->sessions[$session->id] = self::toTheSecond($session);
    }

    public function findByKeyHash(string $keyHash): ?RefreshToken
    {
        foreach ($this->sessions as $session) {
            if ($session->keyHash === $keyHash) {
                return $session;
            }
        }

        return null;
    }

    public function lock(string $id): ?RefreshToken
    {
        $this->transactions?->lock('refresh_tokens', 'RefreshTokenRepository::lock()');

        return $this->sessions[$id] ?? null;
    }

    public function rotate(string $id, string $tokenHash, int $generation, DateTimeImmutable $at, DateTimeImmutable $expiresAt): void
    {
        $session = $this->sessions[$id] ?? null;
        if ($session !== null) {
            $this->sessions[$id] = self::toTheSecond(new RefreshToken(
                $session->id,
                $session->userId,
                $session->keyHash,
                $tokenHash,
                $session->deviceLabel,
                $session->createdAt,
                $at,
                $expiresAt,
                $session->revokedAt,
                $generation,
            ));
        }
    }

    public function revoke(string $id, DateTimeImmutable $at): void
    {
        $session = $this->sessions[$id] ?? null;
        if ($session !== null && $session->revokedAt === null) {
            $this->sessions[$id] = self::revoked($session, $at);
        }
    }

    public function revokeAllOfUser(UserId $userId, DateTimeImmutable $at): void
    {
        foreach ($this->sessions as $id => $session) {
            if ($session->userId->equals($userId) && $session->revokedAt === null) {
                $this->sessions[$id] = self::revoked($session, $at);
            }
        }
    }

    public function deleteAllButLastUsedOfUser(UserId $userId, int $keep): int
    {
        // Revoked sessions neither count nor go here: the cleanup deletes them.
        $own = array_filter(
            $this->sessions,
            static fn (RefreshToken $session): bool => $session->userId->equals($userId) && $session->revokedAt === null,
        );
        // Last used first, and of two used in the same second the one that was created later.
        uasort($own, static fn (RefreshToken $a, RefreshToken $b): int => [$b->rotatedAt ?? $b->createdAt, $b->id] <=> [$a->rotatedAt ?? $a->createdAt, $a->id]);

        $surplus = array_slice($own, $keep, null, true);
        $this->sessions = array_diff_key($this->sessions, $surplus);

        return count($surplus);
    }

    public function deleteExpiredAndRevoked(DateTimeImmutable $now): int
    {
        $before = count($this->sessions);
        $this->sessions = array_filter(
            $this->sessions,
            static fn (RefreshToken $session): bool => $session->revokedAt === null && $session->expiresAt > $now,
        );

        return $before - count($this->sessions);
    }

    /**
     * @return list<RefreshToken> In the order they were added.
     */
    public function all(): array
    {
        return array_values($this->sessions);
    }

    public function snapshot(): array
    {
        return $this->sessions;
    }

    public function restore(array $snapshot): void
    {
        $sessions = [];
        foreach ($snapshot as $id => $session) {
            \assert($session instanceof RefreshToken);
            $sessions[(string) $id] = $session;
        }
        $this->sessions = $sessions;
    }

    private static function revoked(RefreshToken $session, DateTimeImmutable $at): RefreshToken
    {
        return self::toTheSecond(new RefreshToken(
            $session->id,
            $session->userId,
            $session->keyHash,
            $session->tokenHash,
            $session->deviceLabel,
            $session->createdAt,
            $session->rotatedAt,
            $session->expiresAt,
            $at,
            $session->generation,
        ));
    }

    /**
     * The database keeps times to the second (DATETIME), so this repository does as well.
     */
    private static function toTheSecond(RefreshToken $session): RefreshToken
    {
        $cut = static fn (?DateTimeImmutable $time): ?DateTimeImmutable => $time?->setTime(
            (int) $time->format('G'),
            (int) $time->format('i'),
            (int) $time->format('s'),
        );

        return new RefreshToken(
            $session->id,
            $session->userId,
            $session->keyHash,
            $session->tokenHash,
            $session->deviceLabel,
            $cut($session->createdAt) ?? $session->createdAt,
            $cut($session->rotatedAt),
            $cut($session->expiresAt) ?? $session->expiresAt,
            $cut($session->revokedAt),
            $session->generation,
        );
    }
}
