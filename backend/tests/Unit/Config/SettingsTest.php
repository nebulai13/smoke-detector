<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Config;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Config\Environment;
use SmokeDetector\Config\Settings;
use SmokeDetector\Tests\Support\SettingsFixture;

/**
 * The one typed settings object (HLD §4.5 "Config").
 *
 * A wrong or missing value must stop the application at start-up with a message that names the
 * setting, and never fall back to a default or echo a secret.
 */
final class SettingsTest extends TestCase
{
    public function test_a_sender_name_and_a_log_path_may_contain_any_printable_character_of_any_language(): void
    {
        // The app is German first: "Rauchmelder Ä" is a sender name. Counted in characters, not bytes.
        $settings = Settings::fromArray(array_replace_recursive(SettingsFixture::table(), [
            'logFile' => '/home/www/smoke-detector/shared/logs/größe/app.log',
            'mail' => ['fromName' => str_repeat('Ä', 78) . ' ß'],
        ]));

        self::assertSame(str_repeat('Ä', 78) . ' ß', $settings->mail->fromName);
        self::assertSame('/home/www/smoke-detector/shared/logs/größe/app.log', $settings->logFile);
    }

    public function test_settings_table_is_read_into_typed_values(): void
    {
        $settings = Settings::fromArray(SettingsFixture::table());

        self::assertSame(Environment::Testing, $settings->environment);
        self::assertSame('test-release', $settings->release);
        self::assertSame('https://app.example.test', $settings->appUrl);
        self::assertSame('php://memory', $settings->logFile);
        self::assertSame('db.example.test', $settings->database->host);
        self::assertSame(3306, $settings->database->port);
        self::assertSame('smoke_detector_test', $settings->database->name);
        self::assertSame('tester', $settings->database->user);
        self::assertSame('unit-test-database-password', $settings->database->password->reveal());
        self::assertSame(SettingsFixture::JWT_SECRET, $settings->jwtSecret->reveal());
        self::assertSame('null://null', $settings->mail->dsn->reveal());
        self::assertSame('no-reply@example.test', $settings->mail->fromAddress);
        self::assertSame('Smoke Detector Test', $settings->mail->fromName);
        self::assertSame('test-age18-v1', $settings->consents->age18);
        self::assertSame('test-art9-v1', $settings->consents->art9);
        self::assertSame('test-privacy-v1', $settings->consents->privacy);
    }

    public function test_environment_variables_give_the_same_settings_as_the_table(): void
    {
        self::assertEquals(
            Settings::fromArray(SettingsFixture::table()),
            Settings::fromEnvironment(SettingsFixture::environment()),
        );
    }

    public function test_environment_variables_that_are_not_settings_are_ignored(): void
    {
        $environment = SettingsFixture::environment() + ['PATH' => '/usr/bin', 'HOME' => '/root'];

        self::assertEquals(Settings::fromArray(SettingsFixture::table()), Settings::fromEnvironment($environment));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function environmentVariables(): iterable
    {
        foreach (array_keys(SettingsFixture::environment()) as $variable) {
            yield $variable => [$variable];
        }
    }

    #[DataProvider('environmentVariables')]
    public function test_a_missing_environment_variable_stops_with_its_name(string $variable): void
    {
        $environment = SettingsFixture::environment();
        unset($environment[$variable]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("environment variable {$variable}) is missing.");

        Settings::fromEnvironment($environment);
    }

    #[DataProvider('environmentVariables')]
    public function test_an_empty_environment_variable_counts_as_missing(string $variable): void
    {
        $environment = SettingsFixture::environment();
        $environment[$variable] = '';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("environment variable {$variable}) is missing.");

        Settings::fromEnvironment($environment);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function tableKeys(): iterable
    {
        yield 'environment' => [['environment'], '"environment" (environment variable APP_ENV)'];
        yield 'release' => [['release'], '"release" (environment variable APP_RELEASE)'];
        yield 'appUrl' => [['appUrl'], '"appUrl" (environment variable APP_URL)'];
        yield 'logFile' => [['logFile'], '"logFile" (environment variable LOG_FILE)'];
        yield 'database.host' => [['database', 'host'], '"database.host" (environment variable DB_HOST)'];
        yield 'database.port' => [['database', 'port'], '"database.port" (environment variable DB_PORT)'];
        yield 'database.name' => [['database', 'name'], '"database.name" (environment variable DB_NAME)'];
        yield 'database.user' => [['database', 'user'], '"database.user" (environment variable DB_USER)'];
        yield 'database.password' => [['database', 'password'], '"database.password" (environment variable DB_PASSWORD)'];
        yield 'jwt.secret' => [['jwt', 'secret'], '"jwt.secret" (environment variable JWT_SECRET)'];
        yield 'mail.dsn' => [['mail', 'dsn'], '"mail.dsn" (environment variable MAIL_DSN)'];
        yield 'mail.fromAddress' => [['mail', 'fromAddress'], '"mail.fromAddress" (environment variable MAIL_FROM_ADDRESS)'];
        yield 'mail.fromName' => [['mail', 'fromName'], '"mail.fromName" (environment variable MAIL_FROM_NAME)'];
        yield 'consents.age18' => [['consents', 'age18'], '"consents.age18" (environment variable CONSENT_VERSION_AGE18)'];
        yield 'consents.art9' => [['consents', 'art9'], '"consents.art9" (environment variable CONSENT_VERSION_ART9)'];
        yield 'consents.privacy' => [['consents', 'privacy'], '"consents.privacy" (environment variable CONSENT_VERSION_PRIVACY)'];
    }

    /**
     * @param list<string> $path
     */
    #[DataProvider('tableKeys')]
    public function test_a_missing_key_stops_with_its_name(array $path, string $name): void
    {
        $table = self::without(SettingsFixture::table(), $path);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Settings: {$name} is missing.");

        Settings::fromArray($table);
    }

    /**
     * @param list<string> $path
     */
    #[DataProvider('tableKeys')]
    public function test_an_empty_value_is_rejected(array $path, string $name): void
    {
        // The port is the only number, and has its own test.
        $table = self::with(SettingsFixture::table(), $path, $path === ['database', 'port'] ? 0 : '');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Settings: {$name}");

        Settings::fromArray($table);
    }

    public function test_every_key_of_the_table_has_an_environment_variable(): void
    {
        $names = [];
        foreach (self::tableKeys() as $key => [, $name]) {
            self::assertSame(1, preg_match('/environment variable ([A-Z0-9_]+)\)\z/', $name, $match));
            $names[$key] = $match[1] ?? '';
        }

        self::assertSame($names, Settings::ENVIRONMENT_VARIABLES);
        self::assertSame(array_keys(SettingsFixture::environment()), array_values(Settings::ENVIRONMENT_VARIABLES));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function unknownKeys(): iterable
    {
        yield 'top level' => [['jwtSecret' => 'x'], 'Settings: unknown key "jwtSecret".'];
        yield 'database' => [
            ['database' => SettingsFixture::table()['database'] + ['pass' => 'x']],
            'Settings: unknown key "database.pass".',
        ];
        yield 'jwt' => [['jwt' => ['secret' => SettingsFixture::JWT_SECRET, 'ttl' => 900]], 'Settings: unknown key "jwt.ttl".'];
        yield 'mail' => [
            ['mail' => SettingsFixture::table()['mail'] + ['host' => 'smtp.example.test']],
            'Settings: unknown key "mail.host".',
        ];
        yield 'consents' => [
            ['consents' => SettingsFixture::table()['consents'] + ['terms' => 'v1']],
            'Settings: unknown key "consents.terms".',
        ];
    }

    /**
     * A typo in a hand-written settings file must not leave the real setting silently unset.
     *
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('unknownKeys')]
    public function test_an_unknown_key_is_rejected(array $overrides, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Settings::fromArray(array_replace(SettingsFixture::table(), $overrides));
    }

    /**
     * @return iterable<string, array{list<string>, mixed, string}>
     */
    public static function wrongTypes(): iterable
    {
        yield 'section is not a table' => [['database'], 'mysql://x', '"database" must be an array, got string.'];
        yield 'jwt section is not a table' => [['jwt'], SettingsFixture::JWT_SECRET, '"jwt" must be an array, got string.'];
        yield 'mail section is missing' => [['mail'], null, '"mail" must be an array, got null.'];
        yield 'consents section is not a table' => [['consents'], 'v1', '"consents" must be an array, got string.'];
        yield 'consent version as number' => [['consents', 'privacy'], 3, 'must be a string, got int.'];
        yield 'port as text' => [['database', 'port'], '3306', 'must be an integer, got string.'];
        yield 'port as float' => [['database', 'port'], 3306.0, 'must be an integer, got float.'];
        yield 'release as number' => [['release'], 42, 'must be a string, got int.'];
        yield 'secret as array' => [['jwt', 'secret'], ['a'], 'must be a string, got array.'];
        yield 'password as null' => [['database', 'password'], null, 'must be a string, got null.'];
        yield 'environment as bool' => [['environment'], true, 'must be a string, got bool.'];
    }

    /**
     * @param list<string> $path
     */
    #[DataProvider('wrongTypes')]
    public function test_a_value_of_the_wrong_type_is_rejected_and_never_converted(array $path, mixed $value, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Settings::fromArray(self::with(SettingsFixture::table(), $path, $value));
    }

    public function test_jwt_secret_of_exactly_32_bytes_is_accepted(): void
    {
        $secret = str_repeat('k', 32);

        $settings = Settings::fromArray(self::with(SettingsFixture::table(), ['jwt', 'secret'], $secret));

        self::assertSame($secret, $settings->jwtSecret->reveal());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shortSecrets(): iterable
    {
        yield '31 bytes' => [str_repeat('k', 31)];
        yield '1 byte' => ['k'];
        // 16 characters, but 4 bytes each would be 64: the length is counted in bytes, so 31 bytes
        // of multi-byte text are still too short. 15 two-byte characters + 1 = 31 bytes.
        yield '16 characters in 31 bytes' => [str_repeat('ä', 15) . 'k'];
    }

    #[DataProvider('shortSecrets')]
    public function test_jwt_secret_shorter_than_32_bytes_is_rejected_without_echoing_it(string $secret): void
    {
        try {
            Settings::fromArray(self::with(SettingsFixture::table(), ['jwt', 'secret'], $secret));
            self::fail('A JWT secret shorter than 32 bytes was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Settings: "jwt.secret" (environment variable JWT_SECRET) must be at least 32 bytes long.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString($secret, $exception->getMessage());
        }
    }

    public function test_jwt_secret_from_the_environment_is_checked_the_same_way(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('(environment variable JWT_SECRET) must be at least 32 bytes long.');

        Settings::fromEnvironment(['JWT_SECRET' => str_repeat('k', 31)] + SettingsFixture::environment());
    }

    /**
     * @return iterable<string, array{string, Environment}>
     */
    public static function environments(): iterable
    {
        yield 'production' => ['production', Environment::Production];
        yield 'development' => ['development', Environment::Development];
        yield 'testing' => ['testing', Environment::Testing];
    }

    #[DataProvider('environments')]
    public function test_environment_is_one_of_three_names(string $name, Environment $expected): void
    {
        // Production needs a real mail server (see below), the others do not care.
        $mail = ['dsn' => 'smtp://mailbox:its-password@smtp.example.test:587'] + SettingsFixture::table()['mail'];

        self::assertSame($expected, SettingsFixture::settings(['environment' => $name, 'mail' => $mail])->environment);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownEnvironments(): iterable
    {
        yield 'short form' => ['prod'];
        yield 'upper case' => ['Production'];
        yield 'another name' => ['staging'];
        yield 'padded' => [' testing'];
    }

    #[DataProvider('unknownEnvironments')]
    public function test_an_unknown_environment_name_is_rejected(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Settings: "environment" (environment variable APP_ENV) must be "production", "development" or "testing".',
        );

        SettingsFixture::settings(['environment' => $name]);
    }

    public function test_only_production_hides_error_details(): void
    {
        self::assertFalse(Environment::Production->exposesErrorDetails());
        self::assertTrue(Environment::Development->exposesErrorDetails());
        self::assertTrue(Environment::Testing->exposesErrorDetails());
    }

    /**
     * @return iterable<string, array{list<string>, mixed, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'release with a space' => [['release'], 'v1 beta', '"release" (environment variable APP_RELEASE) must be 1 to 64 characters'];
        yield 'release too long' => [['release'], str_repeat('a', 65), '"release" (environment variable APP_RELEASE) must be 1 to 64 characters'];
        yield 'release starts with a dash' => [['release'], '-abc', '"release" (environment variable APP_RELEASE) must be 1 to 64 characters'];
        yield 'app url without scheme' => [['appUrl'], 'app.example.test', '"appUrl" (environment variable APP_URL) must be an http(s) URL'];
        yield 'app url with trailing slash' => [['appUrl'], 'https://app.example.test/', '"appUrl" (environment variable APP_URL) must be an http(s) URL'];
        yield 'app url with query' => [['appUrl'], 'https://app.example.test?x=1', '"appUrl" (environment variable APP_URL) must be an http(s) URL'];
        yield 'app url with credentials' => [['appUrl'], 'https://user:pw@app.example.test', '"appUrl" (environment variable APP_URL) must be an http(s) URL'];
        yield 'app url with another scheme' => [['appUrl'], 'ftp://app.example.test', '"appUrl" (environment variable APP_URL) must be an http(s) URL'];
        yield 'app url with a line break' => [['appUrl'], "https://app.example.test\n", '"appUrl" (environment variable APP_URL) must be an http(s) URL'];
        yield 'host with a DSN separator' => [['database', 'host'], 'db;port=1', '"database.host" (environment variable DB_HOST) must be a host name or an IP address'];
        yield 'host with a space' => [['database', 'host'], 'db host', '"database.host" (environment variable DB_HOST) must be a host name or an IP address'];
        yield 'port zero' => [['database', 'port'], 0, '"database.port" (environment variable DB_PORT) must be a port number from 1 to 65535.'];
        yield 'port negative' => [['database', 'port'], -1, '"database.port" (environment variable DB_PORT) must be a port number from 1 to 65535.'];
        yield 'port too high' => [['database', 'port'], 65536, '"database.port" (environment variable DB_PORT) must be a port number from 1 to 65535.'];
        yield 'schema name with a dash' => [['database', 'name'], 'smoke-detector', '"database.name" (environment variable DB_NAME) must be 1 to 64 characters'];
        yield 'schema name with a DSN separator' => [['database', 'name'], 'a;charset=latin1', '"database.name" (environment variable DB_NAME) must be 1 to 64 characters'];
        yield 'schema name too long' => [['database', 'name'], str_repeat('a', 65), '"database.name" (environment variable DB_NAME) must be 1 to 64 characters'];
        yield 'user with a control character' => [['database', 'user'], "smoke\0", '"database.user" (environment variable DB_USER) must be 1 to 80 printable characters.'];
        yield 'user too long' => [['database', 'user'], str_repeat('u', 81), '"database.user" (environment variable DB_USER) must be 1 to 80 printable characters.'];
        yield 'sender is not an address' => [['mail', 'fromAddress'], 'no-reply', '"mail.fromAddress" (environment variable MAIL_FROM_ADDRESS) must be an email address.'];
        yield 'sender with a header injection' => [['mail', 'fromAddress'], "a@example.test\nBcc: b@example.test", '"mail.fromAddress" (environment variable MAIL_FROM_ADDRESS) must be an email address.'];
        yield 'sender name with a line break' => [['mail', 'fromName'], "Smoke\nDetector", '"mail.fromName" (environment variable MAIL_FROM_NAME) must be 1 to 80 printable characters.'];
        yield 'consent version with a space' => [['consents', 'age18'], 'v 1', '"consents.age18" (environment variable CONSENT_VERSION_AGE18) must be 1 to 32 characters'];
        yield 'consent version too long' => [['consents', 'art9'], str_repeat('1', 33), '"consents.art9" (environment variable CONSENT_VERSION_ART9) must be 1 to 32 characters'];
        yield 'consent version with a line break' => [['consents', 'privacy'], "v1\n", '"consents.privacy" (environment variable CONSENT_VERSION_PRIVACY) must be 1 to 32 characters'];
        yield 'log file with a null byte' => [['logFile'], "/var/log/app.log\0", '"logFile" (environment variable LOG_FILE) must be an absolute file path or a php:// stream.'];
        // Cron starts in another directory than the web server: a relative path would be two files,
        // and for the web one of them would lie inside the document root.
        yield 'log file with a relative path' => [['logFile'], 'logs/app.log', '"logFile" (environment variable LOG_FILE) must be an absolute file path or a php:// stream.'];
        yield 'log file that is only a name' => [['logFile'], 'app.log', '"logFile" (environment variable LOG_FILE) must be an absolute file path or a php:// stream.'];
        yield 'log file that climbs out of the working directory' => [['logFile'], '../app.log', '"logFile" (environment variable LOG_FILE) must be an absolute file path or a php:// stream.'];
        yield 'log file that is a blank' => [['logFile'], ' ', '"logFile" (environment variable LOG_FILE) must be an absolute file path or a php:// stream.'];
        yield 'log file with another stream wrapper' => [['logFile'], 'ftp://example.test/app.log', '"logFile" (environment variable LOG_FILE) must be an absolute file path or a php:// stream.'];
    }

    /**
     * @param list<string> $path
     */
    #[DataProvider('invalidValues')]
    public function test_an_invalid_value_is_rejected_with_the_name_of_the_setting(array $path, mixed $value, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Settings: ' . $message);

        Settings::fromArray(self::with(SettingsFixture::table(), $path, $value));
    }

    /**
     * @return iterable<string, array{list<string>, string|int}>
     */
    public static function boundaryValues(): iterable
    {
        yield 'release of 64 characters' => [['release'], str_repeat('a', 64)];
        yield 'release as a git SHA' => [['release'], '32ce3f8ed5ea65457441693166740639c243e57c'];
        yield 'release with dots and dashes' => [['release'], '2026.11.17-rc_1'];
        yield 'app url with a port' => [['appUrl'], 'http://localhost:5173'];
        yield 'app url with a path' => [['appUrl'], 'https://example.test/app'];
        yield 'host as IPv4' => [['database', 'host'], '127.0.0.1'];
        yield 'port 1' => [['database', 'port'], 1];
        yield 'port 65535' => [['database', 'port'], 65535];
        yield 'schema name of 64 characters' => [['database', 'name'], str_repeat('a', 64)];
        yield 'user of 80 characters' => [['database', 'user'], str_repeat('u', 80)];
        yield 'log stream' => [['logFile'], 'php://stderr'];
        yield 'log path' => [['logFile'], '/home/www/app/shared/logs/app.log'];
    }

    /**
     * @param list<string> $path
     */
    #[DataProvider('boundaryValues')]
    public function test_values_at_the_edge_of_the_rules_are_accepted(array $path, string|int $value): void
    {
        $table = self::with(SettingsFixture::table(), $path, $value);

        self::assertInstanceOf(Settings::class, Settings::fromArray($table));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function portsFromTheEnvironment(): iterable
    {
        yield 'text' => ['mysql'];
        yield 'float' => ['3306.0'];
        yield 'signed' => ['+3306'];
        yield 'negative' => ['-1'];
        yield 'padded' => [' 3306'];
        yield 'hex' => ['0x1F'];
        yield 'beyond any integer' => [str_repeat('9', 30)];
    }

    #[DataProvider('portsFromTheEnvironment')]
    public function test_database_port_from_the_environment_must_be_a_plain_number(string $port): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Settings: "database.port" (environment variable DB_PORT) must be a port number from 1 to 65535.');

        Settings::fromEnvironment(['DB_PORT' => $port] + SettingsFixture::environment());
    }

    public function test_production_requires_an_https_app_url(): void
    {
        // Mail links carry one-time tokens (HLD §4.5), which must never travel over plain HTTP.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Settings: "appUrl" (environment variable APP_URL) must use https in production.');

        SettingsFixture::settings(['environment' => 'production', 'appUrl' => 'http://app.example.test']);
    }

    public function test_production_accepts_an_https_app_url(): void
    {
        $mail = ['dsn' => 'smtp://mailbox:its-password@smtp.example.test:587'] + SettingsFixture::table()['mail'];
        $settings = SettingsFixture::settings(['environment' => 'production', 'appUrl' => 'https://app.example.test', 'mail' => $mail]);

        self::assertSame(Environment::Production, $settings->environment);
    }

    public function test_an_absolute_log_path_and_a_php_stream_are_accepted(): void
    {
        self::assertSame('/home/www/smoke-detector/shared/logs/app.log', SettingsFixture::settings(['logFile' => '/home/www/smoke-detector/shared/logs/app.log'])->logFile);
        self::assertSame('php://stderr', SettingsFixture::settings(['logFile' => 'php://stderr'])->logFile);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mailTransportsThatSendNothing(): iterable
    {
        yield 'the null transport' => ['null://null'];
        yield 'the null transport in capitals' => ['NULL://default'];
    }

    #[DataProvider('mailTransportsThatSendNothing')]
    public function test_FR_ACC_03_production_refuses_a_mail_transport_that_sends_nothing(string $dsn): void
    {
        // The sender would hand every confirmation and reset mail to it and delete the mail as sent.
        $mail = ['dsn' => $dsn] + SettingsFixture::table()['mail'];

        try {
            SettingsFixture::settings(['environment' => 'production', 'mail' => $mail]);
            self::fail('Expected the settings to be refused.');
        } catch (InvalidArgumentException $refused) {
            self::assertSame('Settings: "mail.dsn" (environment variable MAIL_DSN) must name a real mail server in production.', $refused->getMessage());
        }
    }

    public function test_development_and_tests_may_use_the_mail_transport_that_sends_nothing(): void
    {
        self::assertSame('null://null', SettingsFixture::settings(['environment' => 'development'])->mail->dsn->reveal());
        self::assertSame('null://null', SettingsFixture::settings(['environment' => 'testing'])->mail->dsn->reveal());
    }

    public function test_production_accepts_a_real_mail_server(): void
    {
        $mail = ['dsn' => 'smtp://mailbox:its-password@smtp.example.test:587'] + SettingsFixture::table()['mail'];

        $settings = SettingsFixture::settings(['environment' => 'production', 'mail' => $mail]);

        self::assertSame('smtp://mailbox:its-password@smtp.example.test:587', $settings->mail->dsn->reveal());
    }

    public function test_error_messages_never_contain_secret_values(): void
    {
        $table = SettingsFixture::table();
        // PHP shortens string arguments in a stack trace to 15 characters, so the marker comes first.
        $table['database']['password'] = 'LEAK-database-password';
        $table['jwt']['secret'] = 'LEAK-jwt-secret-0123456789-0123456789';
        $table['mail']['dsn'] = 'LEAK://user:LEAK@smtp.example.test:587';
        $table['database']['port'] = 0;

        try {
            Settings::fromArray($table);
            self::fail('The invalid port was accepted.');
        } catch (InvalidArgumentException $exception) {
            $text = $exception->getMessage() . $exception->getTraceAsString();
            self::assertStringNotContainsString('LEAK', $text);
        }
    }

    /**
     * @param array<array-key, mixed> $table
     * @param list<string>            $path
     *
     * @return array<array-key, mixed>
     */
    private static function with(array $table, array $path, mixed $value): array
    {
        $key = array_shift($path);
        self::assertNotNull($key);
        if ($path === []) {
            $table[$key] = $value;

            return $table;
        }

        $section = $table[$key] ?? null;
        self::assertIsArray($section);
        $table[$key] = self::with($section, $path, $value);

        return $table;
    }

    /**
     * @param array<array-key, mixed> $table
     * @param list<string>            $path
     *
     * @return array<array-key, mixed>
     */
    private static function without(array $table, array $path): array
    {
        $key = array_shift($path);
        self::assertNotNull($key);
        if ($path === []) {
            unset($table[$key]);

            return $table;
        }

        $section = $table[$key] ?? null;
        self::assertIsArray($section);
        $table[$key] = self::without($section, $path);

        return $table;
    }
}
