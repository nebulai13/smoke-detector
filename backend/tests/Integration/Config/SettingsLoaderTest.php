<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Integration\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmokeDetector\Config\Environment;
use SmokeDetector\Config\Settings;
use SmokeDetector\Config\SettingsLoader;
use SmokeDetector\Tests\Support\SettingsFixture;

/**
 * Where the settings come from: the file named by SETTINGS_FILE (production), otherwise the
 * environment variables (development and tests). Uses real files, hence an integration test.
 */
final class SettingsLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    /** @var list<string> Directory trees this test made below the temporary directory. */
    private array $trees = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        // Only the trees this test made itself, each below its own random name.
        foreach ($this->trees as $tree) {
            self::removeTree($tree);
        }
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

    public function test_without_a_settings_file_the_environment_variables_are_used(): void
    {
        $settings = (new SettingsLoader())->load(SettingsFixture::environment());

        self::assertEquals(Settings::fromArray(SettingsFixture::table()), $settings);
    }

    public function test_an_empty_settings_file_variable_counts_as_not_set(): void
    {
        $settings = (new SettingsLoader())->load(['SETTINGS_FILE' => ''] + SettingsFixture::environment());

        self::assertEquals(Settings::fromArray(SettingsFixture::table()), $settings);
    }

    public function test_the_file_named_by_SETTINGS_FILE_is_used(): void
    {
        $table = ['environment' => 'production', 'release' => 'from-file'] + SettingsFixture::table();
        // Production refuses the mail transport that sends nothing.
        $table['mail']['dsn'] = 'smtp://mailbox:its-password@smtp.example.test:587';
        $file = $this->settingsFile('<?php return ' . var_export($table, true) . ';');

        $settings = (new SettingsLoader())->load(['SETTINGS_FILE' => $file]);

        self::assertSame(Environment::Production, $settings->environment);
        self::assertSame('from-file', $settings->release);
    }

    public function test_the_file_is_the_only_source_once_it_is_named(): void
    {
        // A stray JWT_SECRET or DB_PASSWORD in the server environment must not override the file.
        $file = $this->settingsFile('<?php return ' . var_export(SettingsFixture::table(), true) . ';');
        $environment = ['SETTINGS_FILE' => $file, 'APP_RELEASE' => 'from-environment', 'JWT_SECRET' => 'short'];

        $settings = (new SettingsLoader())->load($environment);

        self::assertSame('test-release', $settings->release);
        self::assertSame(SettingsFixture::JWT_SECRET, $settings->jwtSecret->reveal());
    }

    public function test_a_file_with_an_invalid_value_stops_with_the_name_of_the_setting(): void
    {
        $table = SettingsFixture::table();
        $table['jwt']['secret'] = 'too-short';
        $file = $this->settingsFile('<?php return ' . var_export($table, true) . ';');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"jwt.secret" (environment variable JWT_SECRET) must be at least 32 bytes long.');

        (new SettingsLoader())->load(['SETTINGS_FILE' => $file]);
    }

    public function test_a_named_file_that_does_not_exist_stops_instead_of_falling_back_to_the_environment(): void
    {
        $missing = sys_get_temp_dir() . '/smoke-detector-no-such-settings-' . bin2hex(random_bytes(8)) . '.php';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "Settings: the file named by SETTINGS_FILE does not exist or cannot be read: {$missing}",
        );

        (new SettingsLoader())->load(['SETTINGS_FILE' => $missing] + SettingsFixture::environment());
    }

    public function test_a_file_that_does_not_return_a_table_is_rejected(): void
    {
        $file = $this->settingsFile('<?php $settings = [];');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Settings: the file named by SETTINGS_FILE must return an array, got int.');

        (new SettingsLoader())->load(['SETTINGS_FILE' => $file]);
    }

    public function test_production_settings_are_not_accepted_from_environment_variables(): void
    {
        // HLD §4.5: production reads the file outside the document root. Environment variables of a
        // web server show up in phpinfo() and crash reports, so secrets do not belong there.
        $environment = ['APP_ENV' => 'production'] + SettingsFixture::environment();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Settings: production reads its settings from a file. Set SETTINGS_FILE to its path '
            . '(template: config/settings.example.php).',
        );

        (new SettingsLoader())->load($environment);
    }

    public function test_a_release_reads_the_shared_settings_file_without_any_environment_variable(): void
    {
        // The hosting's cron starts the wrapper with nothing but PATH (HLD §8).
        $app = $this->release("<?php\n\nreturn " . var_export(['release' => 'from-the-shared-file'] + SettingsFixture::table(), true) . ";\n");

        $settings = (new SettingsLoader())->load(['PATH' => '/usr/local/bin:/usr/bin:/bin'], $app);

        self::assertSame('from-the-shared-file', $settings->release);
    }

    public function test_in_a_release_the_environment_variables_are_not_a_second_source(): void
    {
        $table = ['release' => 'from-the-shared-file'] + SettingsFixture::table();
        unset($table['jwt']['secret']);
        $app = $this->release("<?php\n\nreturn " . var_export($table, true) . ";\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"jwt.secret"');

        // The environment has a JWT secret. It must not fill the gap of the file.
        (new SettingsLoader())->load(SettingsFixture::environment(), $app);
    }

    public function test_SETTINGS_FILE_wins_over_the_file_of_the_release(): void
    {
        $app = $this->release("<?php\n\nreturn " . var_export(['release' => 'from-the-shared-file'] + SettingsFixture::table(), true) . ";\n");
        $named = $this->settingsFile("<?php\n\nreturn " . var_export(['release' => 'from-the-named-file'] + SettingsFixture::table(), true) . ";\n");

        self::assertSame('from-the-named-file', (new SettingsLoader())->load(['SETTINGS_FILE' => $named], $app)->release);
    }

    public function test_in_a_release_a_named_file_that_does_not_exist_still_stops_the_start(): void
    {
        $app = $this->release("<?php\n\nreturn " . var_export(SettingsFixture::table(), true) . ";\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist or cannot be read');

        (new SettingsLoader())->load(['SETTINGS_FILE' => sys_get_temp_dir() . '/smoke-detector-no-such-settings.php'], $app);
    }

    public function test_outside_a_release_the_application_root_changes_nothing(): void
    {
        // The repository: the application root is called "backend". Even with a file lying where
        // a release would look, the environment variables are the source.
        $base = sys_get_temp_dir() . '/smoke-detector-loader-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($base . '/repo/x/backend', 0o700, true));
        self::assertTrue(mkdir($base . '/shared/config', 0o700, true));
        self::assertNotFalse(file_put_contents($base . '/shared/config/local.php', "<?php\n\nreturn " . var_export(['release' => 'must-not-be-read'] + SettingsFixture::table(), true) . ";\n"));
        $this->trees[] = $base;

        $settings = (new SettingsLoader())->load(SettingsFixture::environment(), $base . '/repo/x/backend');

        self::assertSame(SettingsFixture::settings()->release, $settings->release);
    }

    /**
     * Makes <base>/releases/r1/app and <base>/shared/config/local.php with the given content.
     *
     * @return string The application root of the release.
     */
    private function release(string $settingsPhp): string
    {
        $base = sys_get_temp_dir() . '/smoke-detector-loader-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($base . '/releases/r1/app', 0o700, true));
        self::assertTrue(mkdir($base . '/shared/config', 0o700, true));
        self::assertNotFalse(file_put_contents($base . '/shared/config/local.php', $settingsPhp));
        $this->trees[] = $base;

        return $base . '/releases/r1/app';
    }

    private function settingsFile(string $php): string
    {
        $file = sys_get_temp_dir() . '/smoke-detector-settings-' . bin2hex(random_bytes(8)) . '.php';
        self::assertNotFalse(file_put_contents($file, $php));
        $this->files[] = $file;

        return $file;
    }
}
