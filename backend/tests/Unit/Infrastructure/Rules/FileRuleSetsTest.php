<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Infrastructure\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmokeDetector\Infrastructure\Rules\FileRuleSets;
use SmokeDetector\Tests\Support\Paths;
use SmokeDetector\Tests\Unit\Domain\Rules\RuleTables;

/**
 * Where the application gets the rule table from: backend/config/rules/<version>.php (HLD §3).
 */
final class FileRuleSetsTest extends TestCase
{
    public function test_the_current_rule_set_is_the_reviewed_table_of_that_version(): void
    {
        $rules = (new FileRuleSets(Paths::backend() . '/config/rules', 'v1'))->current();

        self::assertEquals(RuleTables::v1(), $rules);
        self::assertSame('v1', $rules->version);
    }

    public function test_the_file_is_read_once(): void
    {
        $ruleSets = new FileRuleSets(Paths::backend() . '/config/rules', 'v1');

        self::assertSame($ruleSets->current(), $ruleSets->current());
    }

    public function test_a_table_that_names_another_version_than_its_file_is_refused(): void
    {
        // Stored results name the version. A file "v2.php" that says "v1" would make two tables one.
        $ruleSets = new FileRuleSets(__DIR__ . '/fixtures', 'v2');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The rule table v2.php names itself "v1".');

        $ruleSets->current();
    }

    public function test_a_missing_rule_table_is_reported_with_its_file(): void
    {
        $ruleSets = new FileRuleSets(__DIR__ . '/fixtures', 'v9');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('There is no rule table v9.php.');

        $ruleSets->current();
    }

    public function test_a_file_that_does_not_return_a_table_is_refused(): void
    {
        $ruleSets = new FileRuleSets(__DIR__ . '/fixtures', 'not-an-array');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The rule table not-an-array.php does not return an array.');

        $ruleSets->current();
    }

    public function test_the_version_cannot_name_a_file_outside_the_directory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FileRuleSets(__DIR__ . '/fixtures', '../../../../../config/rules/v1');
    }
}
