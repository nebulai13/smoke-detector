<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use SmokeDetector\Application\Account\UserId;

/**
 * Makes access tokens (HLD §4.5: JWT, HS256, valid 15 minutes).
 */
interface AccessTokenIssuer
{
    /**
     * @param int $tokenVersion User::$tokenVersion of the account at this moment. The token is
     *                          accepted only as long as the account still has this number.
     */
    public function issue(UserId $userId, int $tokenVersion): AccessToken;
}
