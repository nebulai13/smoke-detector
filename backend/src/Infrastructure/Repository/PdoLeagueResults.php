<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Competition\CalendarPeriod;
use SmokeDetector\Application\Competition\LeagueAward;
use SmokeDetector\Application\Competition\LeagueResult;
use SmokeDetector\Application\Competition\LeagueResults;
use SmokeDetector\Domain\Xp\LeaguePeriod;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The final results of the league periods in the `league_results` table (HLD §4.3): one row per
 * user, period type and period key; the rank in its four columns, NULL until it is decided. A stored
 * score and a stored rank are never changed (HLD §4.9 "Stored for good").
 */
final readonly class PdoLeagueResults implements LeagueResults
{
    private const string COLUMNS = 'user_id, period_type, period_key, b0_tenths, actual_tenths, expected_tenths, smoke_free_days, '
        . 'check_ins, counted_days, rules_version, scored_at, check_at, `rank`, participants, xp, ranked_at';

    public function __construct(private Sql $sql)
    {
    }

    public function find(UserId $userId, CalendarPeriod $period): ?LeagueResult
    {
        $columns = $this->sql->row(
            'SELECT ' . self::COLUMNS . ' FROM league_results WHERE user_id = ? AND period_type = ? AND period_key = ?',
            [$userId->value, $period->type->value, $period->key],
        );

        return $columns === null ? null : self::result(new Row($columns));
    }

    public function save(LeagueResult $result): void
    {
        $names = explode(', ', self::COLUMNS);
        // What was scored is never changed. The rank columns are set only while they are empty, each
        // on its own, so that no assignment reads a column another one has already changed.
        $updates = array_map(static fn (string $name): string => "{$name} = COALESCE({$name}, VALUES({$name}))", array_slice($names, 12));
        $award = $result->award;
        // One statement: two runs at the same moment cannot both find the row missing or empty.
        $this->sql->execute(
            'INSERT INTO league_results (' . self::COLUMNS . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ') '
            . 'ON DUPLICATE KEY UPDATE check_at = VALUES(check_at), ' . implode(', ', $updates),
            [
                $result->userId->value,
                $result->period->type->value,
                $result->period->key,
                $result->b0Tenths,
                $result->actualTenths,
                $result->expectedTenths,
                $result->smokeFreeDays,
                $result->checkIns,
                $result->countedDays,
                $result->rulesVersion,
                Row::sqlTime($result->scoredAt),
                Row::sqlTime($result->checkAt),
                $award?->rank,
                $award?->participants,
                $award?->xp,
                Row::nullableSqlTime($award?->rankedAt),
            ],
        );
    }

    public function unranked(DateTimeImmutable $now, int $limit): array
    {
        return $this->results($this->sql->rows(
            'SELECT ' . self::COLUMNS . ' FROM league_results WHERE ranked_at IS NULL AND check_at <= ? '
            . 'ORDER BY check_at, user_id, period_type, period_key LIMIT ?',
            [Row::sqlTime($now), $limit],
        ));
    }

    public function ofUser(UserId $userId): array
    {
        return $this->results($this->sql->rows(
            'SELECT ' . self::COLUMNS . ' FROM league_results WHERE user_id = ? ORDER BY scored_at, period_type, period_key',
            [$userId->value],
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<LeagueResult>
     */
    private function results(array $rows): array
    {
        return array_map(static fn (array $columns): LeagueResult => self::result(new Row($columns)), $rows);
    }

    private static function result(Row $row): LeagueResult
    {
        $rankedAt = $row->nullableTime('ranked_at');

        return new LeagueResult(
            UserId::fromString($row->string('user_id')),
            CalendarPeriod::fromKey(LeaguePeriod::from($row->string('period_type')), $row->string('period_key')),
            $row->int('b0_tenths'),
            $row->int('actual_tenths'),
            $row->int('expected_tenths'),
            $row->int('smoke_free_days'),
            $row->int('check_ins'),
            $row->int('counted_days'),
            $row->string('rules_version'),
            $row->time('scored_at'),
            $row->time('check_at'),
            $rankedAt === null ? null : new LeagueAward($row->int('rank'), $row->int('participants'), $row->int('xp'), $rankedAt),
        );
    }
}
