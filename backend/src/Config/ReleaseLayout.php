<?php

declare(strict_types=1);

namespace SmokeDetector\Config;

/**
 * Where a release finds what is shared between releases (HLD §4.5 "Config", §8):
 *
 *     <root>/releases/<name>/app      the application root: src/, vendor/, bin/
 *     <root>/shared/config/local.php  the settings, uploaded once by hand
 *     <root>/shared/logs/             the log directory
 *
 * The hosting's cron passes neither arguments nor environment variables, so the place of the
 * settings file has to follow from where the code lies. Only an application root that sits exactly
 * there counts as a release. Anywhere else (the repository, whose application root is "backend")
 * nothing follows from the place, and the environment variables are used.
 */
final readonly class ReleaseLayout
{
    /** The name of the application root inside a release. */
    private const string APP_DIRECTORY = 'app';

    /** The name of the directory that holds the releases. */
    private const string RELEASES_DIRECTORY = 'releases';

    private const string SETTINGS_FILE = '/shared/config/local.php';

    private const string START_FAILURE_LOG = '/shared/logs/cron-start.log';

    private function __construct(
        /** <root> of a release, or null when the application root is not inside one. */
        private ?string $root,
    ) {
    }

    /**
     * @param string $appRoot The directory that holds src/ and vendor/.
     */
    public static function of(string $appRoot): self
    {
        $app = rtrim($appRoot, '/');
        $releases = dirname($app, 2);

        $isRelease = $app !== ''
            && basename($app) === self::APP_DIRECTORY
            && basename($releases) === self::RELEASES_DIRECTORY;

        return new self($isRelease ? dirname($releases) : null);
    }

    /**
     * @return string|null The shared settings file, when this is a release and the file is there.
     */
    public function settingsFile(): ?string
    {
        if ($this->root === null) {
            return null;
        }

        $file = $this->root . self::SETTINGS_FILE;

        return is_file($file) ? $file : null;
    }

    /**
     * Where the cron job notes that it could not start, when this is a release. There is no
     * application log then (its place is a setting), and the hosting's cron keeps no output.
     *
     * @return string|null Named whether or not the file or its directory exist yet.
     */
    public function startFailureLog(): ?string
    {
        return $this->root === null ? null : $this->root . self::START_FAILURE_LOG;
    }
}
