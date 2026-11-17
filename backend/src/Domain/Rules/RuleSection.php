<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Rules;

use InvalidArgumentException;

/**
 * Strict reader for one level of a rule table array.
 *
 * A rule table is edited by hand, so a typo must fail loudly instead of leaving a rule at some default:
 * the exact set of keys is checked up front, and no value is ever converted to another type.
 *
 * @internal Only RuleSet::fromArray() reads rule tables.
 */
final readonly class RuleSection
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
     * @param list<string>            $keys   Exactly these keys must be present.
     */
    public static function root(array $values, array $keys): self
    {
        return self::checked('', $values, $keys);
    }

    /**
     * @param list<string> $keys Exactly these keys must be present in the nested section.
     */
    public function section(string $key, array $keys): self
    {
        $value = $this->values[$key] ?? null;
        if (!is_array($value)) {
            throw $this->wrongType($key, 'an array', $value);
        }

        return self::checked($this->name($key), $value, $keys);
    }

    public function string(string $key): string
    {
        $value = $this->values[$key] ?? null;
        if (!is_string($value)) {
            throw $this->wrongType($key, 'a string', $value);
        }

        return $value;
    }

    public function int(string $key): int
    {
        $value = $this->values[$key] ?? null;
        if (!is_int($value)) {
            throw $this->wrongType($key, 'an integer', $value);
        }

        return $value;
    }

    /**
     * @return list<int>
     */
    public function intList(string $key): array
    {
        $value = $this->values[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->wrongType($key, 'a list of integers', $value);
        }

        $list = [];
        foreach ($value as $item) {
            if (!is_int($item)) {
                throw $this->wrongType($key, 'a list of integers', $value);
            }
            $list[] = $item;
        }

        return $list;
    }

    /**
     * @return array<int, int>
     */
    public function intMap(string $key): array
    {
        $value = $this->values[$key] ?? null;
        if (!is_array($value)) {
            throw $this->wrongType($key, 'a table of integer => integer', $value);
        }

        $map = [];
        foreach ($value as $index => $item) {
            if (!is_int($index) || !is_int($item)) {
                throw $this->wrongType($key, 'a table of integer => integer', $value);
            }
            $map[$index] = $item;
        }

        return $map;
    }

    /**
     * @param array<array-key, mixed> $values
     * @param list<string>            $keys
     */
    private static function checked(string $path, array $values, array $keys): self
    {
        $section = new self($path, $values);

        foreach (array_keys($values) as $key) {
            if (!in_array($key, $keys, true)) {
                throw new InvalidArgumentException('Rule set: unknown key "' . $section->name((string) $key) . '".');
            }
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $values)) {
                throw new InvalidArgumentException('Rule set: missing key "' . $section->name($key) . '".');
            }
        }

        return $section;
    }

    private function name(string $key): string
    {
        return $this->path === '' ? $key : "{$this->path}.{$key}";
    }

    private function wrongType(string $key, string $expected, mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException(
            'Rule set: "' . $this->name($key) . '" must be ' . $expected . ', got ' . get_debug_type($value) . '.',
        );
    }
}
