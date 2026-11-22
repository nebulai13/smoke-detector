<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Privacy;

use DateTimeImmutable;
use DateTimeZone;

/**
 * How values are written in the data export, so that every table writes them the same way.
 */
final readonly class ExportFormat
{
    /**
     * A moment as RFC 3339 in UTC, e.g. "2026-11-17T12:00:00Z".
     */
    public static function time(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function nullableTime(?DateTimeImmutable $time): ?string
    {
        return $time === null ? null : self::time($time);
    }
}
