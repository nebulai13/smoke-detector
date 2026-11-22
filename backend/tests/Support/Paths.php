<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support;

/**
 * Where the files are that tests read.
 */
final class Paths
{
    /** The backend/ directory. */
    public static function backend(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function migrations(): string
    {
        return self::backend() . '/migrations';
    }

    /** The HTTP contract every HTTP test is checked against. */
    public static function openApi(): string
    {
        return dirname(self::backend()) . '/api/openapi.yaml';
    }
}
