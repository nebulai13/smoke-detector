<?php

declare(strict_types=1);

namespace SmokeDetector\Application;

use Throwable;

/**
 * Runs a piece of work so that everything it stores is stored together or not at all.
 *
 * A use case owns the transaction (HLD §4.1): it wraps its repository calls in run(). Repositories
 * and actions never start one.
 */
interface TransactionRunner
{
    /**
     * Commits when $work returns and rolls back when it throws. The exception is passed on unchanged.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws Throwable Whatever $work throws.
     */
    public function run(callable $work): mixed;
}
