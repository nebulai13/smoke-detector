<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Competition\CalendarPeriod;
use SmokeDetector\Application\Competition\LeagueResult;
use SmokeDetector\Application\Competition\LeagueResults;

/**
 * The final results of the league periods, in memory. Behaves like PdoLeagueResults (both pass
 * LeagueResultsContract).
 */
final class InMemoryLeagueResults implements LeagueResults, InMemoryState
{
    /** @var array<string, LeagueResult> By user id, period type and period key. */
    private array $results = [];

    public function __construct(?InMemoryTransactionRunner $transactions = null)
    {
        $transactions?->enlist($this);
    }

    public function find(UserId $userId, CalendarPeriod $period): ?LeagueResult
    {
        return $this->results[self::key($userId, $period)] ?? null;
    }

    public function save(LeagueResult $result): void
    {
        $stored = $this->find($result->userId, $result->period);
        // What was scored stays as it was scored, and a rank that was decided stays decided.
        $this->results[self::key($result->userId, $result->period)] = $stored === null ? $result : new LeagueResult(
            $stored->userId, $stored->period, $stored->b0Tenths, $stored->actualTenths, $stored->expectedTenths, $stored->smokeFreeDays, $stored->checkIns,
            $stored->countedDays, $stored->rulesVersion, $stored->scoredAt, $result->checkAt, $stored->award ?? $result->award,
        );
    }

    public function unranked(DateTimeImmutable $now, int $limit): array
    {
        $results = array_values(array_filter(
            $this->results,
            static fn (LeagueResult $result): bool => $result->award === null && $result->checkAt <= $now,
        ));
        usort($results, static fn (LeagueResult $a, LeagueResult $b): int => self::order($a) <=> self::order($b));

        return array_slice($results, 0, $limit);
    }

    public function ofUser(UserId $userId): array
    {
        $results = array_values(array_filter($this->results, static fn (LeagueResult $result): bool => $result->userId->equals($userId)));
        usort(
            $results,
            static fn (LeagueResult $a, LeagueResult $b): int => [$a->scoredAt, $a->period->type->value, $a->period->key] <=> [$b->scoredAt, $b->period->type->value, $b->period->key],
        );

        return $results;
    }

    /**
     * @return list<LeagueResult> Everything stored, in the order it was first saved: for the tests.
     */
    public function all(): array
    {
        return array_values($this->results);
    }

    public function snapshot(): array
    {
        return $this->results;
    }

    public function restore(array $snapshot): void
    {
        $this->results = [];
        foreach ($snapshot as $key => $result) {
            \assert($result instanceof LeagueResult);
            $this->results[(string) $key] = $result;
        }
    }

    private static function key(UserId $userId, CalendarPeriod $period): string
    {
        return "{$userId->value} {$period->type->value} {$period->key}";
    }

    /**
     * Longest waiting first, then by user, type and key.
     *
     * @return array{DateTimeImmutable, string, string, string}
     */
    private static function order(LeagueResult $result): array
    {
        return [$result->checkAt, $result->userId->value, $result->period->type->value, $result->period->key];
    }
}
