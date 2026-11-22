<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use RuntimeException;

/**
 * The refresh token is not accepted. Deliberately without a reason: unknown, expired, used and
 * revoked look the same to the client. The user has to log in again.
 */
final class InvalidRefreshToken extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The refresh token is invalid.');
    }
}
