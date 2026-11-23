<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Repository;

use DateTimeImmutable;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Competition\Duel;
use SmokeDetector\Application\Competition\DuelDecision;
use SmokeDetector\Application\Competition\Duels;
use SmokeDetector\Application\Competition\DuelStatus;
use SmokeDetector\Application\Competition\DuelWinner;
use SmokeDetector\Application\Competition\SideResult;
use SmokeDetector\Application\Time\LocalDate;
use SmokeDetector\Infrastructure\Database\Row;
use SmokeDetector\Infrastructure\Database\Sql;

/**
 * The duels in the `duels` table (HLD §4.3): one row per duel, a decision in the columns of the two
 * sides. The side of a deleted account has no id, no first day and no result.
 */
final readonly class PdoDuels implements Duels
{
    /** The columns of a side, in the order of SideResult. */
    private const array SIDE = ['b0_tenths', 'actual_tenths', 'expected_tenths', 'smoke_free_days', 'check_ins', 'xp'];

    private const string COLUMNS = 'id, challenger_id, opponent_id, period_days, status, created_at, expires_at, answered_at, '
        . 'challenger_start, opponent_start, decided_at, winner, '
        . 'challenger_b0_tenths, challenger_actual_tenths, challenger_expected_tenths, challenger_smoke_free_days, challenger_check_ins, challenger_xp, '
        . 'opponent_b0_tenths, opponent_actual_tenths, opponent_expected_tenths, opponent_smoke_free_days, opponent_check_ins, opponent_xp, '
        . 'rules_version';

    public function __construct(private Sql $sql)
    {
    }

    public function find(string $id): ?Duel
    {
        $columns = $this->sql->row('SELECT ' . self::COLUMNS . ' FROM duels WHERE id = ?', [$id]);

        return $columns === null ? null : self::duel(new Row($columns));
    }

    public function save(Duel $duel): void
    {
        $names = explode(', ', self::COLUMNS);
        $updates = array_map(static fn (string $name): string => "{$name} = VALUES({$name})", array_slice($names, 1));
        $decision = $duel->decision;
        $this->sql->execute(
            'INSERT INTO duels (' . self::COLUMNS . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ') '
            . 'ON DUPLICATE KEY UPDATE ' . implode(', ', $updates),
            [
                $duel->id,
                $duel->challengerId?->value,
                $duel->opponentId?->value,
                $duel->periodDays,
                $duel->status->value,
                Row::sqlTime($duel->createdAt),
                Row::sqlTime($duel->expiresAt),
                Row::nullableSqlTime($duel->answeredAt),
                $duel->challengerStart?->value,
                $duel->opponentStart?->value,
                Row::nullableSqlTime($decision?->decidedAt),
                $decision?->winner->value,
                ...self::sideValues($decision?->challenger),
                ...self::sideValues($decision?->opponent),
                $decision?->rulesVersion,
            ],
        );
    }

    public function delete(string $id): void
    {
        $this->sql->execute('DELETE FROM duels WHERE id = ?', [$id]);
    }

    public function ofUser(UserId $userId, int $limit): array
    {
        return $this->ofEitherSide($userId, '', 'ORDER BY created_at DESC, id DESC LIMIT ?', [$limit]);
    }

    /**
     * @return list<Duel> Every duel the user is part of, the oldest first: for the data export.
     */
    public function allOf(UserId $userId): array
    {
        return $this->ofEitherSide($userId, '', 'ORDER BY created_at, id');
    }

    public function openOf(UserId $userId): array
    {
        return $this->ofEitherSide($userId, "AND status IN ('proposed', 'active')", 'ORDER BY created_at, id');
    }

    public function wonBy(UserId $userId): int
    {
        // One count on each index, as ofEitherSide reads, instead of one OR.
        $won = $this->sql->value(
            "SELECT (SELECT COUNT(*) FROM duels WHERE challenger_id = ? AND winner = 'challenger') "
            . "+ (SELECT COUNT(*) FROM duels WHERE opponent_id = ? AND winner = 'opponent')",
            [$userId->value, $userId->value],
        );

        return (new Row(['won' => $won]))->int('won');
    }

    public function expired(DateTimeImmutable $now, int $limit): array
    {
        return $this->duels($this->sql->rows(
            'SELECT ' . self::COLUMNS . " FROM duels WHERE status = 'proposed' AND expires_at <= ? ORDER BY expires_at, id LIMIT ?",
            [Row::sqlTime($now), $limit],
        ));
    }

    public function decidable(int $limit): array
    {
        // The last day of a side is its first day plus the days of the duel less one. It is final
        // when it is before the side's next day to finalize. A user without progress is never through.
        $columns = implode(', ', array_map(static fn (string $name): string => "d.{$name}", explode(', ', self::COLUMNS)));

        return $this->duels($this->sql->rows(
            "SELECT {$columns} FROM duels d "
            . 'JOIN user_progress c ON c.user_id = d.challenger_id '
            . 'JOIN user_progress o ON o.user_id = d.opponent_id '
            . "WHERE d.status = 'active' "
            . 'AND DATE_ADD(d.challenger_start, INTERVAL d.period_days - 1 DAY) < c.next_day '
            . 'AND DATE_ADD(d.opponent_start, INTERVAL d.period_days - 1 DAY) < o.next_day '
            . 'ORDER BY d.answered_at, d.id LIMIT ?',
            [$limit],
        ));
    }

    /**
     * The duels of the user from both columns, one read on each index instead of one OR.
     *
     * @param string    $condition More of the WHERE of each part.
     * @param string    $order     ORDER BY (and LIMIT) of the whole.
     * @param list<int> $more      Values for the placeholders of $order.
     *
     * @return list<Duel>
     */
    private function ofEitherSide(UserId $userId, string $condition, string $order, array $more = []): array
    {
        return $this->duels($this->sql->rows(
            '(SELECT ' . self::COLUMNS . " FROM duels WHERE challenger_id = ? {$condition}) "
            . 'UNION ALL (SELECT ' . self::COLUMNS . " FROM duels WHERE opponent_id = ? {$condition}) {$order}",
            [$userId->value, $userId->value, ...$more],
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Duel>
     */
    private function duels(array $rows): array
    {
        return array_map(static fn (array $columns): Duel => self::duel(new Row($columns)), $rows);
    }

    /**
     * @return list<int|null>
     */
    private static function sideValues(?SideResult $side): array
    {
        return $side === null
            ? array_fill(0, count(self::SIDE), null)
            : [$side->b0Tenths, $side->actualTenths, $side->expectedTenths, $side->smokeFreeDays, $side->checkIns, $side->xp];
    }

    private static function duel(Row $row): Duel
    {
        $decidedAt = $row->nullableTime('decided_at');

        return new Duel(
            $row->string('id'),
            self::nullableUserId($row->nullableString('challenger_id')),
            self::nullableUserId($row->nullableString('opponent_id')),
            $row->int('period_days'),
            DuelStatus::from($row->string('status')),
            $row->time('created_at'),
            $row->time('expires_at'),
            $row->nullableTime('answered_at'),
            self::nullableDate($row->nullableString('challenger_start')),
            self::nullableDate($row->nullableString('opponent_start')),
            $decidedAt === null ? null : new DuelDecision(
                $decidedAt,
                DuelWinner::from($row->string('winner')),
                self::side($row, 'challenger_'),
                self::side($row, 'opponent_'),
                $row->string('rules_version'),
            ),
        );
    }

    private static function side(Row $row, string $prefix): ?SideResult
    {
        if ($row->nullableInt($prefix . 'b0_tenths') === null) {
            return null;
        }

        return new SideResult(...array_map(static fn (string $column): int => $row->int($prefix . $column), self::SIDE));
    }

    private static function nullableUserId(?string $id): ?UserId
    {
        return $id === null ? null : UserId::fromString($id);
    }

    private static function nullableDate(?string $date): ?LocalDate
    {
        return $date === null ? null : LocalDate::fromString($date);
    }
}
