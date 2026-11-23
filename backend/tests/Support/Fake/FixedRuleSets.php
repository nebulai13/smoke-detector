<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use SmokeDetector\Application\Plan\RuleSets;
use SmokeDetector\Domain\Rules\RuleSet;

/**
 * The rule set a unit test chooses, without reading a file.
 */
final readonly class FixedRuleSets implements RuleSets
{
    public function __construct(private RuleSet $rules)
    {
    }

    public function current(): RuleSet
    {
        return $this->rules;
    }
}
