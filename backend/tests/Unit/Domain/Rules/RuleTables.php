<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Domain\Rules;

use RuntimeException;
use SmokeDetector\Domain\Rules\RuleSet;

/**
 * Gives tests the real, reviewable rule table (backend/config/rules/v1.php), so that scoring tests
 * run against the numbers that ship and not against a copy of them.
 */
final class RuleTables
{
    public static function directory(): string
    {
        return dirname(__DIR__, 4) . '/config/rules';
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function load(string $file): array
    {
        $table = require $file;
        if (!is_array($table)) {
            throw new RuntimeException("{$file} must return an array.");
        }

        return $table;
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function v1Array(): array
    {
        return self::load(self::directory() . '/v1.php');
    }

    public static function v1(): RuleSet
    {
        return RuleSet::fromArray(self::v1Array());
    }
}
