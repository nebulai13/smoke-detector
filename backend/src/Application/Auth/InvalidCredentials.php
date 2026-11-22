<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use RuntimeException;

/**
 * Login failed. Deliberately without a reason: an unknown email address and a wrong password look
 * the same to the client (FR-ACC-04).
 */
final class InvalidCredentials extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The email address or the password is wrong.');
    }
}
