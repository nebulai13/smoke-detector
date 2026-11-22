<?php

declare(strict_types=1);

namespace SmokeDetector\Config;

use InvalidArgumentException;

/**
 * Strict reader for one level of the settings table.
 *
 * The production settings file is written by hand, so a typo must stop the application instead of
 * leaving a setting unset: the exact set of keys is checked, and no value is converted to another type.
 * A message names the setting and never contains its value, because the value may be a secret.
 *
 * @internal Only Settings::fromArray() reads settings tables.
 */
final readonly class SettingsTable
{
    /**
     * @param array<array-key, mixed> $values
     */
    private function __construct(
        private string $path,
        private array $values,
    ) {
    }

    /**
     * @param array<array-key, mixed> $values
     * @param list<string>            $keys   No other key may be present.
     */
    public static function root(array $values, array $keys): self
    {
        return self::checked('', $values, $keys);
    }

    /**
     * @param list<string> $keys No other key may be present in the nested section.
     */
    public function section(string $key, array $keys): self
    {
        $value = $this->values[$key] ?? null;
        if (!is_array($value)) {
            throw new InvalidArgumentException(
                'Settings: "' . $this->path($key) . '" must be an array, got ' . get_debug_type($value) . '.',
            );
        }

        return self::checked($this->path($key), $value, $keys);
    }

    /**
     * A text value that is present and not empty.
     */
    public function string(string $key): string
    {
        $value = $this->present($key);
        if (!is_string($value)) {
            throw $this->invalid($key, 'must be a string, got ' . get_debug_type($value) . '.');
        }
        if ($value === '') {
            throw $this->invalid($key, 'must not be empty.');
        }

        return $value;
    }

    public function int(string $key): int
    {
        $value = $this->present($key);
        if (!is_int($value)) {
            throw $this->invalid($key, 'must be an integer, got ' . get_debug_type($value) . '.');
        }

        return $value;
    }

    public function secret(string $key): Secret
    {
        return new Secret($this->string($key));
    }

    /**
     * The exception for a value that has the right type but breaks a rule of the setting.
     */
    public function invalid(string $key, string $rule): InvalidArgumentException
    {
        return new InvalidArgumentException('Settings: ' . Settings::describe($this->path($key)) . ' ' . $rule);
    }

    private function present(string $key): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            throw $this->invalid($key, 'is missing.');
        }

        return $this->values[$key];
    }

    /**
     * @param array<array-key, mixed> $values
     * @param list<string>            $keys
     */
    private static function checked(string $path, array $values, array $keys): self
    {
        $table = new self($path, $values);

        foreach (array_keys($values) as $key) {
            if (!in_array($key, $keys, true)) {
                throw new InvalidArgumentException('Settings: unknown key "' . $table->path((string) $key) . '".');
            }
        }

        return $table;
    }

    private function path(string $key): string
    {
        return $this->path === '' ? $key : "{$this->path}.{$key}";
    }
}
