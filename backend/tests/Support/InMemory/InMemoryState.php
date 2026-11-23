<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\InMemory;

/**
 * An in-memory repository that takes part in the transactions of InMemoryTransactionRunner.
 */
interface InMemoryState
{
    /**
     * @return array<array-key, mixed> Everything the repository holds. The stored objects are immutable.
     */
    public function snapshot(): array;

    /**
     * @param array<array-key, mixed> $snapshot What snapshot() returned.
     */
    public function restore(array $snapshot): void;
}
