<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support;

use SmokeDetector\Config\ConsentVersions;
use SmokeDetector\Config\DatabaseSettings;
use SmokeDetector\Config\Environment;
use SmokeDetector\Config\MailSettings;
use SmokeDetector\Config\Secret;
use SmokeDetector\Config\Settings;

/**
 * A complete, valid settings table for tests that need a Settings object without any environment.
 *
 * Integration tests that talk to the real test database use IntegrationTestCase::settings() instead.
 */
final class SettingsFixture
{
    public const string JWT_SECRET = 'unit-test-jwt-secret-0123456789-abcdefghijklmnopqrstuvwxyz';

    /** A mail server that looks real and that no test ever reaches (.test never resolves). */
    public const string PRODUCTION_MAIL_DSN = 'smtp://mailbox:its-password@smtp.example.test:587';

    /**
     * The settings table in the layout of config/settings.example.php.
     *
     * @return array{
     *     environment: string,
     *     release: string,
     *     appUrl: string,
     *     logFile: string,
     *     database: array{host: string, port: int, name: string, user: string, password: string},
     *     jwt: array{secret: string},
     *     mail: array{dsn: string, fromAddress: string, fromName: string},
     *     consents: array{age18: string, art9: string, privacy: string},
     * }
     */
    public static function table(): array
    {
        return [
            'environment' => 'testing',
            'release' => 'test-release',
            'appUrl' => 'https://app.example.test',
            'logFile' => 'php://memory',
            'database' => [
                'host' => 'db.example.test',
                'port' => 3306,
                'name' => 'smoke_detector_test',
                'user' => 'tester',
                'password' => 'unit-test-database-password',
            ],
            'jwt' => [
                'secret' => self::JWT_SECRET,
            ],
            'mail' => [
                'dsn' => 'null://null',
                'fromAddress' => 'no-reply@example.test',
                'fromName' => 'Smoke Detector Test',
            ],
            'consents' => [
                'age18' => 'test-age18-v1',
                'art9' => 'test-art9-v1',
                'privacy' => 'test-privacy-v1',
            ],
        ];
    }

    /**
     * The same settings as environment variables.
     *
     * @return array<string, string>
     */
    public static function environment(): array
    {
        return [
            'APP_ENV' => 'testing',
            'APP_RELEASE' => 'test-release',
            'APP_URL' => 'https://app.example.test',
            'LOG_FILE' => 'php://memory',
            'DB_HOST' => 'db.example.test',
            'DB_PORT' => '3306',
            'DB_NAME' => 'smoke_detector_test',
            'DB_USER' => 'tester',
            'DB_PASSWORD' => 'unit-test-database-password',
            'JWT_SECRET' => self::JWT_SECRET,
            'MAIL_DSN' => 'null://null',
            'MAIL_FROM_ADDRESS' => 'no-reply@example.test',
            'MAIL_FROM_NAME' => 'Smoke Detector Test',
            'CONSENT_VERSION_AGE18' => 'test-age18-v1',
            'CONSENT_VERSION_ART9' => 'test-art9-v1',
            'CONSENT_VERSION_PRIVACY' => 'test-privacy-v1',
        ];
    }

    /**
     * A copy of existing settings with some parts replaced, e.g. the test run's settings in production mode.
     */
    public static function modified(
        Settings $settings,
        ?Environment $environment = null,
        ?DatabaseSettings $database = null,
        ?ConsentVersions $consents = null,
        ?string $logFile = null,
        ?string $mailDsn = null,
    ): Settings {
        return new Settings(
            environment: $environment ?? $settings->environment,
            release: $settings->release,
            appUrl: $settings->appUrl,
            logFile: $logFile ?? $settings->logFile,
            database: $database ?? $settings->database,
            jwtSecret: $settings->jwtSecret,
            // Production refuses the mail transport that sends nothing, which is what tests run with.
            // A copy in production mode therefore names a mail server, one that is never contacted.
            mail: ($mailDsn ??= $environment === Environment::Production ? self::PRODUCTION_MAIL_DSN : null) === null
                ? $settings->mail
                : new MailSettings(new Secret($mailDsn), $settings->mail->fromAddress, $settings->mail->fromName),
            consents: $consents ?? $settings->consents,
        );
    }

    /**
     * The database settings of $settings, pointed at a port where nothing listens: connecting is
     * refused at once, like a database server that is down.
     */
    public static function unreachableDatabase(Settings $settings): DatabaseSettings
    {
        $database = $settings->database;

        return new DatabaseSettings($database->host, 1, $database->name, $database->user, $database->password);
    }

    /**
     * @param array<string, mixed> $overrides Top-level keys to replace, e.g. ['environment' => 'production'].
     */
    public static function settings(array $overrides = []): Settings
    {
        return Settings::fromArray(array_replace(self::table(), $overrides));
    }
}
