<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use RuntimeException;

/**
 * The access token is not accepted. Deliberately without a reason: every kind of bad token gets
 * the same answer.
 */
final class InvalidAccessToken extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The access token is invalid.');
    }
}
