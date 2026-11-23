<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Health;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SmokeDetector\Application\Health\CronHeartbeat;

/**
 * The cron heartbeat as one small file: the time of the last successful run, in UTC, as one line
 * of text ("2026-11-17T12:04:05Z").
 *
 * A file and not a table: the cron job and the web requests run on the same server and share the
 * log directory (HLD §8 "shared/logs"), /health can report the heartbeat while the database is
 * down, and no migration is needed. The file lies next to the application log. Where the log goes
 * to a php:// stream (development, tests) there is no such place, and no heartbeat is kept.
 */
final readonly class FileCronHeartbeat implements CronHeartbeat
{
    private const string FORMAT = 'Y-m-d\TH:i:s\Z';

    /** Longer than any time this class writes. More is not read: the file is not ours then. */
    private const int MAX_BYTES = 64;

    public function __construct(
        /** Null: there is no place to keep a heartbeat. */
        private ?string $file,
    ) {
    }

    /**
     * @param string $logFile Settings::$logFile.
     */
    public static function besideLogFile(string $logFile): self
    {
        return new self(str_starts_with($logFile, 'php://') ? null : dirname($logFile) . '/cron-heartbeat');
    }

    public function beat(DateTimeImmutable $at): void
    {
        if ($this->file === null) {
            return;
        }

        // Written under another name and renamed: a request that reads at this moment sees the
        // old time or the new one, never half a line.
        $temporary = $this->file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $line = $at->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT) . "\n";
        if (@file_put_contents($temporary, $line) === false || !@rename($temporary, $this->file)) {
            @unlink($temporary);

            // Without the path: the message ends up in the cron log and on the console.
            throw new RuntimeException('The cron heartbeat could not be written.');
        }
    }

    public function lastBeat(): ?DateTimeImmutable
    {
        if ($this->file === null || !is_file($this->file)) {
            return null;
        }

        $content = @file_get_contents($this->file, false, null, 0, self::MAX_BYTES);
        if ($content === false) {
            return null;
        }
        $text = rtrim($content, "\n");
        $time = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $text, new DateTimeZone('UTC'));

        // createFromFormat() rolls "02-30" over into March instead of failing, hence the round trip.
        return $time === false || $time->format(self::FORMAT) !== $text ? null : $time;
    }
}
