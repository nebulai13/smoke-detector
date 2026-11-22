<?php

declare(strict_types=1);

namespace SmokeDetector\Console;

use Psr\Log\LoggerInterface;
use SmokeDetector\Bootstrap\ContainerFactory;
use SmokeDetector\Config\ReleaseLayout;
use SmokeDetector\Config\SettingsLoader;
use Throwable;

/**
 * The one way into the application for the hosting's cron. bin/cron only calls run().
 *
 * The cron of the hosting takes one path without arguments and offers nowhere to send output to
 * (HLD §8). So this runs `cron:run` and nothing else, prints nothing, and writes the one line of
 * the run to the application log instead, where every entry carries its time in UTC: as "info"
 * when everything worked, as "error" when a step failed. The exit code says the same.
 *
 * Only when the application cannot start at all (invalid settings) is there no log to write to.
 * That is reported on standard error, like bin/console does, and, in a release, appended to
 * shared/logs/cron-start.log (ReleaseLayout): the hosting's cron keeps no output.
 */
final readonly class CronEntryPoint
{
    /** The directory that holds src/ and vendor/: where a release finds its settings (ReleaseLayout). */
    private string $appRoot;

    public function __construct(?string $appRoot = null)
    {
        $this->appRoot = $appRoot ?? dirname(__DIR__, 2);
    }

    /**
     * @param array<array-key, mixed> $environment Name => value of the environment variables.
     * @param Output                  $output      Only for a failed start.
     *
     * @return int The exit code of the process.
     */
    public function run(array $environment, Output $output): int
    {
        // The server's PHP runs in CET. Nothing in this process may produce local time by accident.
        date_default_timezone_set('UTC');

        try {
            $settings = (new SettingsLoader())->load($environment, $this->appRoot);
            $container = (new ContainerFactory())->create($settings);
            $console = $container->get(Console::class);
            $logger = $container->get(LoggerInterface::class);
        } catch (Throwable $failure) {
            // Invalid or missing settings. The message names the setting, never its value.
            $output->error('The application could not start: ' . $failure::class . ': ' . $failure->getMessage());
            $this->noteStartFailure($failure);

            return 1;
        }
        if (!$console instanceof Console || !$logger instanceof LoggerInterface) {
            $output->error('The application could not start: the container has no console or no log.');

            return 1;
        }

        $said = new CollectedOutput();
        $exitCode = $console->run(['cron:run'], $said);

        $summary = 'cron:run: ' . implode(' ', [...$said->lines(), ...$said->errors()]);
        if ($exitCode === 0) {
            $logger->info($summary);
        } else {
            $logger->error($summary);
        }

        return $exitCode;
    }

    /**
     * Appends one line to the start-failure log of the release, best effort: a start that failed
     * must not fail a second time over its report. Outside a release there is no such file.
     *
     * The class of the failure only, not its message. Most messages name a setting and never its
     * value, but not all: the loader's names the path of the settings file, and a settings file
     * with a syntax error gives a ParseError that quotes a piece of the file, which may be a
     * password. Standard error, which the hosting's cron throws away, gets the message.
     */
    private function noteStartFailure(Throwable $failure): void
    {
        $log = ReleaseLayout::of($this->appRoot)->startFailureLog();
        if ($log === null) {
            return;
        }

        $directory = dirname($log);
        if (!is_dir($directory)) {
            @mkdir($directory, 0o750, true);
        }
        @file_put_contents($log, gmdate('Y-m-d\TH:i:s\Z') . ' cron could not start: ' . $failure::class . "\n", FILE_APPEND | LOCK_EX);
    }
}
