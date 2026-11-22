<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use RuntimeException;

/**
 * The token of a mail link is not accepted. Deliberately without a reason: unknown, expired and
 * already used look the same to the client.
 */
final class InvalidToken extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The token is invalid, expired or already used.');
    }
}
