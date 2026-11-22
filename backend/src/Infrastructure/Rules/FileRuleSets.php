<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Rules;

use InvalidArgumentException;
use RuntimeException;
use SmokeDetector\Application\Plan\RuleSets;
use SmokeDetector\Domain\Rules\RuleSet;

/**
 * Reads the rule table from backend/config/rules/<version>.php (HLD §3), once per request.
 *
 * Not readonly: it remembers the table it has read.
 */
final class FileRuleSets implements RuleSets
{
    private ?RuleSet $current = null;

    /**
     * @param string $directory      Where the rule tables are.
     * @param string $currentVersion Which one is in force, e.g. "v1": the file name without ".php".
     */
    public function __construct(
        private readonly string $directory,
        private readonly string $currentVersion,
    ) {
        // The same characters a RuleSet allows for its version. Nothing that could leave the directory.
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,31}\z/', $currentVersion) !== 1) {
            throw new InvalidArgumentException('A rule set version is 1 to 32 characters of a-z, 0-9, ".", "_" or "-".');
        }
    }

    public function current(): RuleSet
    {
        return $this->current ??= $this->read();
    }

    private function read(): RuleSet
    {
        $name = $this->currentVersion . '.php';
        $file = $this->directory . '/' . $name;
        if (!is_file($file)) {
            throw new RuntimeException("There is no rule table {$name}.");
        }

        $table = require $file;
        if (!is_array($table)) {
            throw new RuntimeException("The rule table {$name} does not return an array.");
        }

        $rules = RuleSet::fromArray($table);
        // Stored results name their version. A file that names another one would make two tables one.
        if ($rules->version !== $this->currentVersion) {
            throw new RuntimeException("The rule table {$name} names itself \"{$rules->version}\".");
        }

        return $rules;
    }
}
