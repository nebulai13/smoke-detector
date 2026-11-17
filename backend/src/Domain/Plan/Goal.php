<?php

declare(strict_types=1);

namespace SmokeDetector\Domain\Plan;

use InvalidArgumentException;

/**
 * What the user is aiming for: quit (target 0) or reduce to a fixed number of sessions per day.
 */
final readonly class Goal
{
    private function __construct(public int $target)
    {
    }

    public static function quit(): self
    {
        return new self(0);
    }

    public static function reduceTo(int $sessionsPerDay): self
    {
        if ($sessionsPerDay < 1) {
            throw new InvalidArgumentException('A reduce target must be at least 1. Use Goal::quit() for 0.');
        }

        return new self($sessionsPerDay);
    }

    public function isQuit(): bool
    {
        return $this->target === 0;
    }
}
