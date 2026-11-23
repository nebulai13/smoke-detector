<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps what was logged, so a test can check it (and check what was NOT logged, such as secrets).
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /**
     * @param array<array-key, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_scalar($level) ? (string) $level : get_debug_type($level),
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * Everything that was logged, as one text to search in.
     */
    public function text(): string
    {
        return (string) json_encode($this->records, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    }
}
