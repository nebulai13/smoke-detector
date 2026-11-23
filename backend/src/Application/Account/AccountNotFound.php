<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Account;

use RuntimeException;

/**
 * The account a request acts for does not exist: it was deleted after the request was let in
 * (FR-ACC-08). Answered like a token that is not accepted, 401 "unauthorized".
 */
final class AccountNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The account does not exist.');
    }
}
