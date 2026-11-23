<?php

declare(strict_types=1);

namespace SmokeDetector\Infrastructure\Auth;

use DomainException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use SmokeDetector\Application\Account\UserId;
use SmokeDetector\Application\Auth\AccessToken;
use SmokeDetector\Application\Auth\AccessTokenIssuer;
use SmokeDetector\Application\Auth\AccessTokenVerifier;
use SmokeDetector\Application\Auth\InvalidAccessToken;
use SmokeDetector\Application\Auth\TokenGenerator;
use SmokeDetector\Application\Auth\VerifiedAccessToken;
use SmokeDetector\Config\Secret;
use TypeError;
use UnexpectedValueException;

/**
 * Access tokens as JWTs (HLD §4.5): HS256 with the secret of the settings, valid 15 minutes, with
 * the claims sub (user id), iat, exp, jti and ver (the account's token version, see Authenticate).
 *
 * The token itself is checked without the database. Authenticate then looks at the account: a
 * password reset or an account deletion ends the access tokens that are out at once. A logout ends
 * the refresh token of that device only: the access token it last got keeps working until it
 * expires, at most 15 minutes, which is why it lives so short.
 */
final readonly class JwtAccessTokens implements AccessTokenIssuer, AccessTokenVerifier
{
    public const int LIFETIME_SECONDS = 900;

    /** The one algorithm that is issued and accepted. */
    private const string ALGORITHM = 'HS256';

    public function __construct(
        private Secret $secret,
        private ClockInterface $clock,
        /** Makes the jti, which tells two tokens of the same second apart. */
        private TokenGenerator $tokenIds,
    ) {
    }

    public function issue(UserId $userId, int $tokenVersion): AccessToken
    {
        $now = $this->clock->now()->getTimestamp();

        $token = JWT::encode(
            [
                'sub' => $userId->value,
                'iat' => $now,
                'exp' => $now + self::LIFETIME_SECONDS,
                'jti' => $this->tokenIds->generate(),
                'ver' => $tokenVersion,
            ],
            $this->secret->reveal(),
            self::ALGORITHM,
        );

        return new AccessToken($token, self::LIFETIME_SECONDS);
    }

    public function verify(#[SensitiveParameter] string $token): VerifiedAccessToken
    {
        $now = $this->clock->now()->getTimestamp();

        // The library reads the time from this static property, or from the system clock when it
        // is null. Ours is the injected clock, so it is set for this one call and cleared again.
        JWT::$timestamp = $now;
        try {
            // The key carries the algorithm: a token whose header names any other one (none, HS512,
            // RS256, …) is refused before its signature is even looked at.
            $claims = JWT::decode($token, new Key($this->secret->reveal(), self::ALGORITHM));
        } catch (UnexpectedValueException | DomainException | InvalidArgumentException | TypeError) {
            // Malformed, wrong algorithm, wrong signature, expired, not valid yet: all the same.
            throw new InvalidAccessToken();
        } finally {
            JWT::$timestamp = null;
        }

        $sub = $claims->sub ?? null;
        $iat = $claims->iat ?? null;
        $exp = $claims->exp ?? null;
        $jti = $claims->jti ?? null;
        $ver = $claims->ver ?? null;

        // Our own tokens always have all five claims with these types. The library only checks iat
        // and exp when they are present, so a signed token without exp would never expire.
        if (
            !is_string($sub)
            || !is_int($iat)
            || !is_int($exp)
            || !is_string($jti)
            || $jti === ''
            || !is_int($ver)
            || $ver < 0
            || $iat > $now
            || $exp <= $now
            || $exp - $iat > self::LIFETIME_SECONDS
        ) {
            throw new InvalidAccessToken();
        }

        try {
            return new VerifiedAccessToken(UserId::fromString($sub), $ver);
        } catch (InvalidArgumentException) {
            throw new InvalidAccessToken();
        }
    }
}
