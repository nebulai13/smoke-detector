<?php

declare(strict_types=1);

namespace SmokeDetector\Console;

use SmokeDetector\Bootstrap\ContainerFactory;
use SmokeDetector\Config\SettingsLoader;
use Throwable;

/**
 * The one way into the application for the command line. bin/console only calls run().
 */
final readonly class ConsoleEntryPoint
{
    /** The directory that holds src/ and vendor/: where a release finds its settings (ReleaseLayout). */
    private string $appRoot;

    public function __construct(?string $appRoot = null)
    {
        $this->appRoot = $appRoot ?? dirname(__DIR__, 2);
    }

    /**
     * @param list<string>            $arguments   The command line without the script name.
     * @param array<array-key, mixed> $environment Name => value of the environment variables.
     *
     * @return int The exit code of the process.
     */
    public function run(array $arguments, array $environment, Output $output): int
    {
        // The server's PHP runs in CET. Nothing in this process may produce local time by accident.
        date_default_timezone_set('UTC');

        try {
            $settings = (new SettingsLoader())->load($environment, $this->appRoot);
            $console = (new ContainerFactory())->create($settings)->get(Console::class);
        } catch (Throwable $failure) {
            // Invalid or missing settings. The message names the setting, never its value.
            $output->error('The application could not start: ' . $failure::class . ': ' . $failure->getMessage());

            return 1;
        }
        if (!$console instanceof Console) {
            $output->error('The application could not start: the container has no console.');

            return 1;
        }

        return $console->run($arguments, $output);
    }
}
