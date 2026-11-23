<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use SensitiveParameter;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Account\UserRepository;

/**
 * Says who a request with an access token is (HLD §4.5 "Login and tokens").
 *
 * The token is checked without the database: signature, algorithm, expiry. What the token cannot
 * know is what happened to its account since, and an access token lives for 15 minutes. So the
 * account is looked up for every request:
 *
 * - once it is deleted (FR-ACC-08), its tokens open nothing any more, on any endpoint,
 * - once its password was reset (FR-ACC-05), the tokens from before are refused: the reset raised
 *   the account's token version, and a token only counts under the version it was issued for.
 */
final readonly class Authenticate
{
    public function __construct(
        private AccessTokenVerifier $tokens,
        private UserRepository $users,
    ) {
    }

    /**
     * @throws InvalidAccessToken When the token is not accepted, or its account does not exist
     *                            (any more), is not confirmed, or has had its password reset since.
     *                            One answer for all of them.
     */
    public function execute(#[SensitiveParameter] string $accessToken): UserId
    {
        $token = $this->tokens->verify($accessToken);

        $user = $this->users->find($token->userId);
        if ($user === null || !$user->isVerified() || $user->tokenVersion !== $token->tokenVersion) {
            throw new InvalidAccessToken();
        }

        return $user->id;
    }
}
