<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Plan;

use SmokeDetector\Domain\Rules\RuleSet;

/**
 * Gives use cases the rule table: every tunable number of the game under one version name.
 */
interface RuleSets
{
    /**
     * The rule set new results are calculated with. What is stored names its version (NFR-04).
     */
    public function current(): RuleSet;
}
