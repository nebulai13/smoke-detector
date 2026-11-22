<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Privacy;

use RuntimeException;

/**
 * The password that was entered again to confirm a step is not the password of the account.
 */
final class WrongPassword extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The password is wrong.');
    }
}
