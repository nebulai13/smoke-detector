<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Config\Environment;
use SmokeDetector\Config\Settings;

/**
 * config/settings.example.php is the template for the production settings file. It must name every
 * key (and no other), and must not be usable as it is.
 */
final class SettingsExampleTest extends TestCase
{
    public function test_the_example_has_every_key_and_loads_once_the_secrets_are_filled_in(): void
    {
        $table = self::example();
        $table['database']['password'] = 'filled-in-password';
        $table['jwt']['secret'] = str_repeat('s', Settings::MIN_JWT_SECRET_BYTES);
        $table['mail']['dsn'] = 'smtp://user:password@smtp.example.org:587';

        $settings = Settings::fromArray($table);

        self::assertSame(Environment::Production, $settings->environment);
    }

    public function test_the_example_contains_no_secret_and_cannot_be_used_unchanged(): void
    {
        $table = self::example();

        self::assertSame('', $table['database']['password']);
        self::assertSame('', $table['jwt']['secret']);
        self::assertSame('', $table['mail']['dsn']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty.');

        Settings::fromArray($table);
    }

    /**
     * @return array{database: array<array-key, mixed>, jwt: array<array-key, mixed>, mail: array<array-key, mixed>, ...}
     */
    private static function example(): array
    {
        $table = require dirname(__DIR__, 3) . '/config/settings.example.php';

        self::assertIsArray($table);
        self::assertIsArray($table['database'] ?? null);
        self::assertIsArray($table['jwt'] ?? null);
        self::assertIsArray($table['mail'] ?? null);

        return $table;
    }
}
