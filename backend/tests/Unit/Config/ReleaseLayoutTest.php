<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use SmokeDetector\Config\ReleaseLayout;

/**
 * Where a release finds what is shared between releases (HLD §8, §4.5 "Config").
 *
 *   <root>/releases/<name>/app      the application root: src/, vendor/, bin/
 *   <root>/shared/config/local.php  the settings, uploaded once by hand
 *   <root>/shared/logs/             the log directory
 *
 * The hosting's cron passes neither arguments nor environment variables, so the place of the
 * settings file has to follow from where the code lies. In the repository (application root
 * "backend") nothing follows from it: there the environment variables are used.
 */
final class ReleaseLayoutTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/smoke-detector-layout-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->base, 0o700, true));
    }

    protected function tearDown(): void
    {
        // Only what this test made, below the directory it made for itself.
        self::removeTree($this->base);
    }

    public function test_a_release_finds_the_shared_settings_file(): void
    {
        $app = $this->directory('site/releases/0a1b2c3/app');
        $settings = $this->file('site/shared/config/local.php');

        self::assertSame($settings, ReleaseLayout::of($app)->settingsFile());
    }

    public function test_the_name_of_the_release_does_not_matter(): void
    {
        $this->file('site/shared/config/local.php');

        self::assertNotNull(ReleaseLayout::of($this->directory('site/releases/2026-11-22_18-00/app'))->settingsFile());
        self::assertNotNull(ReleaseLayout::of($this->directory('site/releases/x/app'))->settingsFile());
    }

    public function test_a_release_without_a_settings_file_finds_none(): void
    {
        $app = $this->directory('site/releases/0a1b2c3/app');
        $this->directory('site/shared/config');

        self::assertNull(ReleaseLayout::of($app)->settingsFile());
    }

    public function test_a_directory_with_the_name_of_the_settings_file_is_not_a_settings_file(): void
    {
        $app = $this->directory('site/releases/0a1b2c3/app');
        $this->directory('site/shared/config/local.php');

        self::assertNull(ReleaseLayout::of($app)->settingsFile());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function placesThatAreNoRelease(): iterable
    {
        yield 'the repository: the application root is called backend' => ['site/releases/0a1b2c3/backend'];
        yield 'an app folder that is not inside a release' => ['site/app'];
        yield 'an app folder one level too high' => ['site/releases/app'];
        yield 'an app folder one level too deep' => ['site/releases/0a1b2c3/build/app'];
        yield 'the folder is called release, not releases' => ['site/release/0a1b2c3/app'];
        yield 'another spelling of the name' => ['site/releases/0a1b2c3/App'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('placesThatAreNoRelease')]
    public function test_outside_a_release_nothing_is_found_even_when_a_file_lies_where_one_would_be_looked_for(string $appRoot): void
    {
        $app = $this->directory($appRoot);
        // Three levels above each of these application roots, so a careless rule would find it.
        $this->file('site/shared/config/local.php');
        $this->file('shared/config/local.php');
        $this->file('site/releases/shared/config/local.php');

        $layout = ReleaseLayout::of($app);

        self::assertNull($layout->settingsFile());
        self::assertNull($layout->startFailureLog());
    }

    public function test_a_trailing_slash_changes_nothing(): void
    {
        $app = $this->directory('site/releases/0a1b2c3/app');
        $settings = $this->file('site/shared/config/local.php');

        self::assertSame($settings, ReleaseLayout::of($app . '/')->settingsFile());
    }

    public function test_a_release_names_the_file_for_a_start_that_failed(): void
    {
        $app = $this->directory('site/releases/0a1b2c3/app');

        // Named whether or not the settings file exists: a missing settings file is the very
        // failure it is for. The directory need not exist yet either.
        self::assertSame($this->base . '/site/shared/logs/cron-start.log', ReleaseLayout::of($app)->startFailureLog());
    }

    private function directory(string $relative): string
    {
        $path = $this->base . '/' . $relative;
        if (!is_dir($path)) {
            self::assertTrue(mkdir($path, 0o700, true));
        }

        return $path;
    }

    private function file(string $relative): string
    {
        $path = $this->base . '/' . $relative;
        $this->directory(dirname($relative));
        self::assertNotFalse(file_put_contents($path, "<?php\n\nreturn [];\n"));

        return $path;
    }

    private static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
            rmdir($path);

            return;
        }
        unlink($path);
    }
}
