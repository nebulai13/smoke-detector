<?php

declare(strict_types=1);

namespace SmokeDetector\Config;

use InvalidArgumentException;
use RuntimeException;

/**
 * Decides where the settings come from (HLD §4.5 "Config").
 *
 * - SETTINGS_FILE is set: that PHP file returns the settings table, and nothing else is read. A
 *   named file that is missing stops the start.
 * - Otherwise, in a release (ReleaseLayout: the application root is <root>/releases/<name>/app)
 *   whose <root>/shared/config/local.php exists: that file, and nothing else. This is how
 *   production works, also for the hosting's cron, which passes no environment variables. The
 *   file lives outside the document root and is never committed.
 * - Otherwise the environment variables of Settings::ENVIRONMENT_VARIABLES are read. This is how
 *   development (docker-compose.yml) and the tests (phpunit.xml) work.
 */
final readonly class SettingsLoader
{
    public const string FILE_VARIABLE = 'SETTINGS_FILE';

    /**
     * @param array<array-key, mixed> $environment Name => value, as returned by getenv().
     * @param string|null             $appRoot     The directory that holds src/ and vendor/, for
     *                                             finding the settings file of a release.
     */
    public function load(array $environment, ?string $appRoot = null): Settings
    {
        $file = $environment[self::FILE_VARIABLE] ?? null;
        if (is_string($file) && $file !== '') {
            return Settings::fromArray($this->read($file, 'the file named by ' . self::FILE_VARIABLE));
        }

        $shared = $appRoot === null ? null : ReleaseLayout::of($appRoot)->settingsFile();
        if ($shared !== null) {
            return Settings::fromArray($this->read($shared, 'the settings file of the release'));
        }

        // Refused before any value is looked at: whatever else is wrong with these variables, the
        // first thing to fix is where production takes its settings from. The environment of a web
        // server shows up in phpinfo() and in crash reports.
        if (($environment[Settings::ENVIRONMENT_VARIABLES['environment']] ?? null) === Environment::Production->value) {
            throw new InvalidArgumentException(
                'Settings: production reads its settings from a file. Set ' . self::FILE_VARIABLE
                . ' to its path (template: config/settings.example.php).',
            );
        }

        return Settings::fromEnvironment($environment);
    }

    /**
     * @param string $source How a message names the file.
     *
     * @return array<array-key, mixed>
     */
    private function read(string $file, string $source): array
    {
        // A named file that is missing must stop the application. Falling back to the environment
        // would start production with whatever happens to be set there.
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException(
                'Settings: ' . $source . ' does not exist or cannot be read: ' . $file,
            );
        }

        // A static closure, so the file sees neither $this nor any variable of this method.
        $table = (static fn (): mixed => require $file)();
        if (!is_array($table)) {
            throw new RuntimeException(
                'Settings: ' . $source . ' must return an array, got '
                . get_debug_type($table) . '.',
            );
        }

        return $table;
    }
}
