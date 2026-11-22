<?php

declare(strict_types=1);

namespace SmokeDetector\Config;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * A value that must never reach a log, a dump or an error message: a password, a signing key, a DSN
 * with credentials.
 *
 * The value is only handed out by reveal(), so a search for "reveal(" lists every place a secret is used.
 */
final readonly class Secret
{
    public function __construct(
        #[SensitiveParameter]
        private string $value,
    ) {
        if ($value === '') {
            throw new InvalidArgumentException('A secret must not be empty.');
        }
    }

    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * What var_dump(), print_r() and debuggers show instead of the value.
     *
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '[secret]'];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        // A serialized secret ends up in a cache, a session or a queue, readable by whoever reads that.
        throw new LogicException('A secret cannot be serialized.');
    }
}
