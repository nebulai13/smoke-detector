<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Day\DayRecord;
use SmokeDetector\Application\Day\DayRecords;
use SmokeDetector\Application\Time\LocalDate;

/**
 * Day records that note which of their methods were called, and pass everything on.
 */
final class RecordingDayRecords implements DayRecords
{
    /** @var list<string> The names of the methods called, in order. */
    public array $calls = [];

    public function __construct(private readonly DayRecords $inner)
    {
    }

    public function find(UserId $userId, LocalDate $date): ?DayRecord
    {
        $this->calls[] = 'find';

        return $this->inner->find($userId, $date);
    }

    public function save(DayRecord $day): void
    {
        $this->calls[] = 'save';
        $this->inner->save($day);
    }

    public function between(UserId $userId, LocalDate $from, LocalDate $to): array
    {
        $this->calls[] = 'between';

        return $this->inner->between($userId, $from, $to);
    }

    public function isCheckedInOn(UserId $userId, LocalDate $date): bool
    {
        $this->calls[] = 'isCheckedInOn';

        return $this->inner->isCheckedInOn($userId, $date);
    }

    public function lastMissedUntil(UserId $userId, LocalDate $day): ?LocalDate
    {
        $this->calls[] = 'lastMissedUntil';

        return $this->inner->lastMissedUntil($userId, $day);
    }
}
