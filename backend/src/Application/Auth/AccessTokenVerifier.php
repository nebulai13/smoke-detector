<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use SensitiveParameter;

/**
 * Checks the access token of a request.
 */
interface AccessTokenVerifier
{
    /**
     * @return VerifiedAccessToken Whom the token was issued to, and under which token version of
     *                             the account. Whether that is still the account's version is not
     *                             for the verifier to say (see Authenticate).
     *
     * @throws InvalidAccessToken When the token is malformed, not signed by us with the expected
     *                            algorithm, expired, or lacks a claim.
     */
    public function verify(#[SensitiveParameter] string $token): VerifiedAccessToken;
}
