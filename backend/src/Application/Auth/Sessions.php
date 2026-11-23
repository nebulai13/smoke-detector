<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use DateInterval;
use DateTimeImmutable;
use SensitiveParameter;
use SmokeDetector\Application\Account\User;
use SmokeDetector\Application\IdGenerator;

/**
 * Hands out token pairs (HLD §4.5 "Login and tokens"). Called inside the use case's transaction,
 * which holds the lock on the account.
 *
 * A refresh token is "<key>.<secret>": a random key that stays for the session, and a secret that
 * follows from the key and the generation of the token (RefreshSecrets). The session's row holds
 * the hash of the key, the hash of the current token and its generation, and no token.
 */
final readonly class Sessions
{
    /** A refresh token is valid 60 days. Each rotation starts the 60 days again. */
    public const int REFRESH_TOKEN_LIFETIME_SECONDS = 5_184_000;

    /**
     * How long after a refresh the token that was just replaced is still answered, with the same
     * new token again (SRS OQ-12). Two browser tabs that refresh at the same moment, and a client
     * that repeats a refresh whose answer got lost, present that token. Without the tolerance each
     * of them would end the session.
     */
    public const int REPLACED_TOKEN_TOLERANCE_SECONDS = 10;

    /**
     * How many sessions (devices) one account can have. A login beyond that ends the session that
     * was unused for the longest time. Without a bound, logging in again and again would fill the
     * table, since a successful login is not rate-limited.
     */
    public const int MAX_PER_USER = 20;

    private const string SEPARATOR = '.';

    public function __construct(
        private RefreshTokenRepository $refreshTokens,
        private TokenGenerator $generator,
        private RefreshSecrets $secrets,
        private AccessTokenIssuer $accessTokens,
        private IdGenerator $ids,
    ) {
    }

    /**
     * A login: a new session with its first token (generation 0).
     */
    public function start(User $user, ?DeviceLabel $deviceLabel, DateTimeImmutable $now): Session
    {
        $key = $this->generator->generate();
        $token = $this->token($key, 0);
        $expiresAt = self::expiry($now);

        $this->refreshTokens->add(new RefreshToken(
            $this->ids->next(),
            $user->id,
            TokenHash::of($key),
            TokenHash::of($token),
            $deviceLabel,
            $now,
            null,
            $expiresAt,
            null,
            0,
        ));
        $this->refreshTokens->deleteAllButLastUsedOfUser($user->id, self::MAX_PER_USER);

        return new Session($this->accessTokens->issue($user->id, $user->tokenVersion), $token, $expiresAt);
    }

    /**
     * A refresh: the successor of $presented, the current token of $session, takes its place.
     */
    public function rotate(User $user, RefreshToken $session, #[SensitiveParameter] string $presented, DateTimeImmutable $now): Session
    {
        $generation = $session->generation + 1;
        $token = $this->token(self::keyIn($presented), $generation);
        $expiresAt = self::expiry($now);

        $this->refreshTokens->rotate($session->id, TokenHash::of($token), $generation, $now, $expiresAt);

        return new Session($this->accessTokens->issue($user->id, $user->tokenVersion), $token, $expiresAt);
    }

    /**
     * A refresh that is repeated (see wasJustReplaced()): the current token of $session once more,
     * as the first answer carried it, with a new access token. Nothing is stored and nothing is
     * extended: whoever gets this answer and whoever got the first one hold the same token.
     */
    public function repeat(User $user, RefreshToken $session, #[SensitiveParameter] string $presented): Session
    {
        return new Session(
            $this->accessTokens->issue($user->id, $user->tokenVersion),
            $this->token(self::keyIn($presented), $session->generation),
            $session->expiresAt,
        );
    }

    /**
     * The session a token names by its key, whatever its secret is worth. A plain read.
     */
    public function find(#[SensitiveParameter] string $token): ?RefreshToken
    {
        $key = self::keyOf($token);

        return $key === null ? null : $this->refreshTokens->findByKeyHash(TokenHash::of($key));
    }

    /**
     * Whether the token is the one the session currently has, and not an older one or a made-up one
     * with the same key. It must be the token this server makes for the session's generation, and
     * the one whose hash is stored: a token from before the secrets were derived (a random secret)
     * is not current, whatever the row says.
     *
     * @param RefreshToken $session The session find() gave for this token: it has the token's key.
     */
    public function isCurrent(RefreshToken $session, #[SensitiveParameter] string $token): bool
    {
        return hash_equals($session->tokenHash, TokenHash::of($token))
            && hash_equals($this->token(self::keyIn($token), $session->generation), $token);
    }

    /**
     * Whether the token is the one the session had before its current one, and was replaced no
     * more than REPLACED_TOKEN_TOLERANCE_SECONDS ago. Then it is answered with repeat(). Any older
     * token of the session, and this one after the tolerance, is a token shown again: the caller
     * ends the session, as before.
     *
     * @param RefreshToken $session The session find() gave for this token: it has the token's key.
     */
    public function wasJustReplaced(RefreshToken $session, #[SensitiveParameter] string $token, DateTimeImmutable $now): bool
    {
        if ($session->rotatedAt === null) {
            return false;
        }
        $key = self::keyIn($token);

        return $now->getTimestamp() - $session->rotatedAt->getTimestamp() <= self::REPLACED_TOKEN_TOLERANCE_SECONDS
            && hash_equals($this->token($key, $session->generation - 1), $token)
            // What repeat() will hand out must be the token the session really has.
            && hash_equals($session->tokenHash, TokenHash::of($this->token($key, $session->generation)));
    }

    /**
     * The refresh token of that generation of the session with this key.
     */
    private function token(#[SensitiveParameter] string $key, int $generation): string
    {
        return $key . self::SEPARATOR . $this->secrets->secret($key, $generation);
    }

    /**
     * @return string|null The key part of "<key>.<secret>", or null when the text is not built so.
     */
    private static function keyOf(#[SensitiveParameter] string $token): ?string
    {
        $parts = explode(self::SEPARATOR, $token);

        return count($parts) === 2 ? $parts[0] : null;
    }

    /**
     * The key part of a token that find() has found a session for.
     */
    private static function keyIn(#[SensitiveParameter] string $token): string
    {
        return explode(self::SEPARATOR, $token)[0];
    }

    private static function expiry(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->add(new DateInterval('PT' . self::REFRESH_TOKEN_LIFETIME_SECONDS . 'S'));
    }
}
