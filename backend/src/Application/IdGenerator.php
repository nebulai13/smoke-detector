<?php

declare(strict_types=1);

namespace SmokeDetector\Application;

/**
 * Makes the ids of new rows (HLD §4.3: UUID version 7, so new rows are appended to the index).
 */
interface IdGenerator
{
    /**
     * @return string A new UUID in lower case with dashes. Never the same twice.
     */
    public function next(): string;
}
