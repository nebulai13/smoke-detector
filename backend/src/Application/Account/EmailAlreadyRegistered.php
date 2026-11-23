<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Account;

use RuntimeException;

/**
 * Another account with this email address was stored in the meantime (two registrations at the
 * same moment). Never shown to a client: the use case tries again and finds the other account.
 */
final class EmailAlreadyRegistered extends RuntimeException
{
    public function __construct()
    {
        // No address in the message: it may be logged.
        parent::__construct('An account with this email address exists already.');
    }
}
