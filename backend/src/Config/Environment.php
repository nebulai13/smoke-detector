<?php

declare(strict_types=1);

namespace SmokeDetector\Config;

/**
 * Where the application runs. The name comes from the settings ("environment" / APP_ENV).
 */
enum Environment: string
{
    case Production = 'production';
    case Development = 'development';
    case Testing = 'testing';

    /**
     * Whether an unexpected error may describe itself (exception class and message) in the HTTP response.
     *
     * Production never does: an exception message can name tables, paths or addresses.
     */
    public function exposesErrorDetails(): bool
    {
        return $this !== self::Production;
    }
}
