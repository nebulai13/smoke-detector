<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Infrastructure\Health;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmokeDetector\Infrastructure\Health\FileCronHeartbeat;
use SmokeDetector\Tests\Support\TestData;

/**
 * The cron heartbeat as a small file next to the application log (HLD §7): written by the cron
 * job, read by GET /health.
 */
final class FileCronHeartbeatTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/smoke-detector-heartbeat-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->directory) ?: [], ['.', '..']) as $name) {
            $path = $this->directory . '/' . $name;
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->directory);
    }

    public function test_before_the_first_run_there_is_no_heartbeat(): void
    {
        self::assertNull($this->heartbeat()->lastBeat());
    }

    public function test_a_recorded_run_is_read_back_in_utc_also_by_another_process(): void
    {
        $this->heartbeat()->beat(new \DateTimeImmutable('2026-11-17 13:04:05', new \DateTimeZone('Europe/Berlin')));

        // A new object: the web request that reads it is not the cron job that wrote it.
        $read = $this->heartbeat()->lastBeat();
        self::assertEquals(TestData::time('2026-11-17 12:04:05'), $read);
        self::assertSame('UTC', $read?->getTimezone()->getName());
        self::assertSame("2026-11-17T12:04:05Z\n", file_get_contents($this->directory . '/cron-heartbeat'));
    }

    public function test_a_later_run_replaces_the_earlier_one_and_leaves_no_other_file_behind(): void
    {
        $this->heartbeat()->beat(TestData::time('2026-11-17 12:00:00'));
        $this->heartbeat()->beat(TestData::time('2026-11-17 12:01:02'));

        self::assertEquals(TestData::time('2026-11-17 12:01:02'), $this->heartbeat()->lastBeat());
        // Written to a file of its own and then renamed: a reader never sees half a time.
        self::assertSame(['cron-heartbeat'], array_values(array_diff(scandir($this->directory) ?: [], ['.', '..'])));
    }

    public function test_the_file_lies_next_to_the_application_log(): void
    {
        $heartbeat = FileCronHeartbeat::besideLogFile($this->directory . '/app.log');

        $heartbeat->beat(TestData::time('2026-11-17 12:00:00'));

        self::assertFileExists($this->directory . '/cron-heartbeat');
        self::assertEquals(TestData::time('2026-11-17 12:00:00'), FileCronHeartbeat::besideLogFile($this->directory . '/app.log')->lastBeat());
    }

    public function test_with_the_log_on_a_stream_there_is_no_place_for_a_heartbeat(): void
    {
        // Development and tests log to php://stderr or php://memory: nothing is written anywhere.
        $heartbeat = FileCronHeartbeat::besideLogFile('php://stderr');

        $heartbeat->beat(TestData::time('2026-11-17 12:00:00'));

        self::assertNull($heartbeat->lastBeat());
        self::assertSame([], array_values(array_diff(scandir($this->directory) ?: [], ['.', '..'])));
    }

    public function test_a_file_that_does_not_hold_a_time_is_no_heartbeat_and_no_error(): void
    {
        foreach (['', 'yesterday', '2026-11-17 12:00:00', '2026-02-30T12:00:00Z', "2026-11-17T12:00:00Z\nand more", str_repeat('9', 5000)] as $content) {
            file_put_contents($this->directory . '/cron-heartbeat', $content);

            self::assertNull($this->heartbeat()->lastBeat(), $content);
        }

        unlink($this->directory . '/cron-heartbeat');
        mkdir($this->directory . '/a-directory');
        self::assertNull((new FileCronHeartbeat($this->directory . '/a-directory'))->lastBeat());
    }

    public function test_a_heartbeat_that_cannot_be_written_is_an_error_that_names_no_path(): void
    {
        $heartbeat = new FileCronHeartbeat($this->directory . '/no-such-directory/cron-heartbeat');

        try {
            $heartbeat->beat(TestData::time('2026-11-17 12:00:00'));
            self::fail('Expected the failure to be reported.');
        } catch (RuntimeException $failure) {
            self::assertSame('The cron heartbeat could not be written.', $failure->getMessage());
        }
        self::assertNull($heartbeat->lastBeat());
    }

    private function heartbeat(): FileCronHeartbeat
    {
        return new FileCronHeartbeat($this->directory . '/cron-heartbeat');
    }
}
