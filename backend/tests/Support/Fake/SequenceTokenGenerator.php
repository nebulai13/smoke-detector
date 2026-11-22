<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use SmokeDetector\Application\Auth\TokenGenerator;

/**
 * Predictable tokens for unit tests: "token-1", "token-2", …
 */
final class SequenceTokenGenerator implements TokenGenerator
{
    private int $count = 0;

    public function generate(): string
    {
        return 'token-' . ++$this->count;
    }

    /** The token that was made last. */
    public function last(): string
    {
        return 'token-' . $this->count;
    }
}
