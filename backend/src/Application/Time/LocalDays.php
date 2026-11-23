<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Time;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SmokeDetector\Application\Account\TimeZone;

/**
 * The days of one user (HLD §4.6 "Local days", FR-I18N-02): the zone of a local date, when the
 * date starts and ends, and which date an instant belongs to. Pure calculation from the user's
 * time zone history.
 *
 * - The zone of date D is the newest change valid on D; before the first change, the first zone.
 * - D starts at 00:00 of D in the zone of D (where the clock skips midnight: at its first instant)
 *   and ends where D+1 starts.
 * - An instant belongs to the latest date that has started. After a move east the next date can
 *   start before the last one in the old zone has; the latest date still wins, so every instant has
 *   exactly one date, and a date can be left without any.
 */
final readonly class LocalDays
{
    /** @var non-empty-list<ZoneChange> Oldest first. */
    private array $changes;

    /**
     * @param list<ZoneChange> $changes In any order, at most one per date.
     *
     * @throws InvalidArgumentException Without a change, or with two for one date.
     */
    public function __construct(array $changes)
    {
        $byDate = [];
        foreach ($changes as $change) {
            if (isset($byDate[$change->validFrom->value])) {
                throw new InvalidArgumentException('A time zone history has at most one entry per date.');
            }
            $byDate[$change->validFrom->value] = $change;
        }
        // "YYYY-MM-DD" sorts as the dates do.
        ksort($byDate, SORT_STRING);
        $sorted = array_values($byDate);
        if ($sorted === []) {
            throw new InvalidArgumentException('A time zone history needs at least one entry.');
        }

        $this->changes = $sorted;
    }

    public function zoneOn(LocalDate $date): TimeZone
    {
        $zone = $this->changes[0]->zone;
        foreach ($this->changes as $change) {
            if (!$change->validFrom->isAfter($date)) {
                $zone = $change->zone;
            }
        }

        return $zone;
    }

    /**
     * The first instant of the date, in UTC.
     */
    public function startOf(LocalDate $date): DateTimeImmutable
    {
        // A date without a time is its midnight. A midnight the clock skips is moved forward by the
        // length of the gap: to the first instant of the date.
        return (new DateTimeImmutable($date->value, new DateTimeZone($this->zoneOn($date)->name)))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The instant the next date starts, in UTC. Before startOf() for a date no instant belongs to.
     */
    public function endOf(LocalDate $date): DateTimeImmutable
    {
        return $this->startOf($date->plusDays(1));
    }

    /**
     * The latest date whose start is not after the instant.
     *
     * Each change covers the dates from its own date to the day before the next change (the first
     * also every date before it). Within one zone the dates start in calendar order, so the latest
     * date of a range that has started is the instant's date on the zone's clock, held to the end of
     * the range. Every date of a later range is later than every date of an earlier one: the answer
     * comes from the newest range in which a date has started. In the first range one always has.
     */
    public function dateOf(DateTimeImmutable $instant): LocalDate
    {
        $index = count($this->changes) - 1;
        $date = $this->latestStartedIn($index, $instant);
        while ($index > 0 && $date->isBefore($this->changes[$index]->validFrom)) {
            $index--;
            $date = $this->latestStartedIn($index, $instant);
        }

        return $date;
    }

    /**
     * The latest date counted in the zone of change $index that has started at the instant, held to
     * the end of the range of that change, without looking at where the range begins.
     */
    private function latestStartedIn(int $index, DateTimeImmutable $instant): LocalDate
    {
        $date = LocalDate::of($instant, $this->changes[$index]->zone);
        $next = $this->changes[$index + 1] ?? null;
        if ($next !== null && !$date->isBefore($next->validFrom)) {
            return $next->validFrom->plusDays(-1);
        }

        return $date;
    }
}
