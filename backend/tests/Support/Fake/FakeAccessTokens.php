<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Support\Fake;

use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Auth\AccessToken;
use SmokeDetector\Application\Auth\AccessTokenIssuer;
use SmokeDetector\Application\Auth\AccessTokenVerifier;
use SmokeDetector\Application\Auth\InvalidAccessToken;
use SmokeDetector\Application\Auth\VerifiedAccessToken;

/**
 * Readable access tokens for unit tests: "access-<n>-v<token version>-for-<user id>". Anything else
 * is refused.
 */
final class FakeAccessTokens implements AccessTokenIssuer, AccessTokenVerifier
{
    private int $count = 0;

    public function issue(UserId $userId, int $tokenVersion): AccessToken
    {
        return new AccessToken('access-' . ++$this->count . "-v{$tokenVersion}-for-" . $userId->value, 900);
    }

    public function verify(string $token): VerifiedAccessToken
    {
        if (preg_match('/\Aaccess-\d+-v(\d+)-for-([0-9a-f-]{36})\z/', $token, $match) !== 1) {
            throw new InvalidAccessToken();
        }

        return new VerifiedAccessToken(UserId::fromString($match[2]), (int) $match[1]);
    }
}
