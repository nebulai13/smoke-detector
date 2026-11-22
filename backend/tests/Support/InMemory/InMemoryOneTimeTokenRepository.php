<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use DateTimeImmutable;
use LogicException;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Auth\OneTimeToken;
use SmokeDetector\Application\Auth\OneTimeTokenRepository;
use SmokeDetector\Application\Auth\TokenPurpose;

/**
 * The tokens of mail links, in memory. Behaves like PdoOneTimeTokenRepository (both pass
 * OneTimeTokenRepositoryContract).
 */
final class InMemoryOneTimeTokenRepository implements OneTimeTokenRepository, InMemoryState
{
    /** @var array<string, OneTimeToken> By id. */
    private array $tokens = [];

    public function __construct(private readonly ?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function put(OneTimeToken $token): void
    {
        foreach ($this->tokens as $id => $existing) {
            if ($existing->userId->equals($token->userId) && $existing->purpose === $token->purpose) {
                // Like the database: the row of this user and purpose stays, id included, with the
                // new token in it.
                $this->tokens[$id] = new OneTimeToken($existing->id, $token->userId, $token->purpose, $token->tokenHash, $token->expiresAt, $token->usedAt, $token->failedAttempts);

                return;
            }
            if ($existing->id === $token->id || $existing->tokenHash === $token->tokenHash) {
                throw new LogicException('Duplicate one-time token.');
            }
        }

        $this->tokens[$token->id] = $token;
    }

    public function findByHash(TokenPurpose $purpose, string $tokenHash): ?OneTimeToken
    {
        foreach ($this->tokens as $token) {
            if ($token->purpose === $purpose && $token->tokenHash === $tokenHash) {
                return $token;
            }
        }

        return null;
    }

    public function lockByHash(TokenPurpose $purpose, string $tokenHash): ?OneTimeToken
    {
        $this->transactions?->lock('one_time_tokens', 'OneTimeTokenRepository::lockByHash()');

        return $this->findByHash($purpose, $tokenHash);
    }

    public function markUsed(string $id, DateTimeImmutable $at): void
    {
        $token = $this->tokens[$id] ?? null;
        if ($token !== null) {
            $this->tokens[$id] = new OneTimeToken(
                $token->id,
                $token->userId,
                $token->purpose,
                $token->tokenHash,
                $token->expiresAt,
                $at,
                $token->failedAttempts,
            );
        }
    }

    public function addFailedAttempt(string $id): void
    {
        $token = $this->tokens[$id] ?? null;
        if ($token !== null) {
            $this->tokens[$id] = new OneTimeToken(
                $token->id,
                $token->userId,
                $token->purpose,
                $token->tokenHash,
                $token->expiresAt,
                $token->usedAt,
                $token->failedAttempts + 1,
            );
        }
    }

    public function delete(string $id): void
    {
        unset($this->tokens[$id]);
    }

    public function deleteExpiredAndUsed(DateTimeImmutable $now): int
    {
        $before = count($this->tokens);
        $this->tokens = array_filter(
            $this->tokens,
            static fn (OneTimeToken $token): bool => $token->usedAt === null && $token->expiresAt > $now,
        );

        return $before - count($this->tokens);
    }

    /**
     * @return list<OneTimeToken>
     */
    public function all(): array
    {
        return array_values($this->tokens);
    }

    public function snapshot(): array
    {
        return $this->tokens;
    }

    public function restore(array $snapshot): void
    {
        $tokens = [];
        foreach ($snapshot as $id => $token) {
            \assert($token instanceof OneTimeToken);
            $tokens[(string) $id] = $token;
        }
        $this->tokens = $tokens;
    }
}
