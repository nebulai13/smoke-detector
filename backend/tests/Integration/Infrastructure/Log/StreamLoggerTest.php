<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Infrastructure\Log;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SmokeDetector\Infrastructure\Clock\FrozenClock;
use SmokeDetector\Infrastructure\Log\StreamLogger;

/**
 * The application log: one JSON object per line, appended to the file of the settings (HLD §7).
 * Uses real files, hence an integration test.
 */
final class StreamLoggerTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/smoke-detector-log-' . bin2hex(random_bytes(8)) . '.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function test_it_is_a_psr_logger(): void
    {
        self::assertInstanceOf(LoggerInterface::class, $this->logger());
    }

    public function test_an_entry_is_one_line_of_json_with_utc_time_level_message_and_context(): void
    {
        $this->logger()->error('Unhandled exception', ['path' => '/api/v1/health', 'line' => 12]);

        self::assertSame(
            '{"time":"2026-11-17T12:00:00.000Z","level":"error","message":"Unhandled exception",'
            . '"context":{"path":"/api/v1/health","line":12}}' . "\n",
            file_get_contents($this->file),
        );
    }

    public function test_entries_are_appended(): void
    {
        $logger = $this->logger();

        $logger->info('first');
        $logger->warning('second');

        $lines = file($this->file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(2, $lines);
        self::assertStringContainsString('"level":"info","message":"first"', $lines[0]);
        self::assertStringContainsString('"level":"warning","message":"second"', $lines[1]);
    }

    public function test_a_line_break_in_a_message_cannot_forge_a_second_entry(): void
    {
        $this->logger()->error("first line\n{\"time\":\"forged\"}", ['value' => "a\nb"]);

        $lines = file($this->file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(1, $lines);
        $entry = json_decode($lines[0], true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($entry);
        self::assertSame("first line\n{\"time\":\"forged\"}", $entry['message']);
    }

    public function test_objects_in_the_context_are_named_and_not_dumped(): void
    {
        $object = new class () {
            public string $password = 'LEAK-in-a-property';
        };

        $this->logger()->error('failed', [
            'object' => $object,
            'exception' => new RuntimeException('The cause.'),
            'nested' => ['list' => [1, true, null, 1.5]],
        ]);

        $entry = json_decode((string) file_get_contents($this->file), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($entry);
        self::assertIsArray($entry['context']);
        self::assertIsString($entry['context']['object']);
        self::assertStringStartsWith('class@anonymous', $entry['context']['object']);
        self::assertSame('RuntimeException: The cause.', $entry['context']['exception']);
        self::assertSame(['list' => [1, true, null, 1.5]], $entry['context']['nested']);
        self::assertStringNotContainsString('LEAK', (string) file_get_contents($this->file));
    }

    public function test_text_that_is_not_utf8_is_still_logged(): void
    {
        $this->logger()->error("broken \xC3\x28 text");

        $entry = json_decode((string) file_get_contents($this->file), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($entry);
        self::assertIsString($entry['message']);
        self::assertStringStartsWith('broken ', $entry['message']);
    }

    public function test_a_php_stream_can_be_the_log(): void
    {
        $logger = new StreamLogger('php://memory', FrozenClock::at('2026-11-17 12:00:00'));

        $logger->error('goes nowhere');

        self::assertFileDoesNotExist($this->file);
    }

    public function test_a_log_file_that_cannot_be_written_never_breaks_the_request(): void
    {
        // The message then goes to PHP's own error log, which is redirected into a file for this test.
        $phpLog = $this->file . '.php';
        $before = ini_set('error_log', $phpLog);
        try {
            $logger = new StreamLogger('/no/such/directory/app.log', FrozenClock::at('2026-11-17 12:00:00'));

            $logger->error('could not be written');

            self::assertStringContainsString('"message":"could not be written"', (string) file_get_contents($phpLog));
        } finally {
            ini_set('error_log', (string) $before);
            @unlink($phpLog);
        }
    }

    private function logger(): StreamLogger
    {
        return new StreamLogger($this->file, FrozenClock::at('2026-11-17 12:00:00'));
    }
}
