<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Log;

use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

/**
 * The application log: one JSON object per line, appended to the file named in the settings
 * (outside the document root, HLD §7), or to a php:// stream in development.
 *
 * Callers decide what is logged, and must never pass secrets, tokens or password hashes. This class
 * only makes sure that what it is given cannot do harm: objects are named, not dumped, and a line
 * break in a value cannot start a forged entry.
 */
final class StreamLogger extends AbstractLogger
{
    private const int FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;

    private const int MAX_DEPTH = 6;

    public function __construct(
        private readonly string $file,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $line = json_encode([
            'time' => $this->clock->now()->format('Y-m-d\TH:i:s.v\Z'),
            'level' => is_scalar($level) ? (string) $level : get_debug_type($level),
            'message' => (string) $message,
            'context' => $this->plain($context, 0),
        ], self::FLAGS);
        if ($line === false) {
            $line = '{"level":"error","message":"A log entry could not be encoded."}';
        }

        // Entries of parallel requests must not interleave. php:// streams cannot be locked.
        $flags = str_starts_with($this->file, 'php://') ? FILE_APPEND : FILE_APPEND | LOCK_EX;
        // Logging must never break the request it describes: a failure here falls back to PHP's own log.
        if (@file_put_contents($this->file, $line . "\n", $flags) === false) {
            error_log($line);
        }
    }

    /**
     * Reduces a context value to text, numbers, booleans, null and arrays of those.
     */
    private function plain(mixed $value, int $depth): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if ($value instanceof Throwable) {
            return $value::class . ': ' . $value->getMessage();
        }
        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return '[…]';
            }

            return array_map(fn (mixed $item): mixed => $this->plain($item, $depth + 1), $value);
        }

        // Never the properties of an object: they may hold exactly what must not be logged.
        return get_debug_type($value);
    }
}
