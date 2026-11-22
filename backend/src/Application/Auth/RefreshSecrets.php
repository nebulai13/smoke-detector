<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

use SensitiveParameter;

/**
 * Makes the secret part of a refresh token (HLD §4.5 "Login and tokens").
 *
 * A refresh token is "<key>.<secret>". The key is random and stays for the session. The secret is
 * not random: it follows from the key and from the generation of the token (0 for the token of the
 * login, one more after every refresh), under a key only the server has. So the server can make the
 * token of any generation again when it is handed the session's key, without ever storing a token.
 * That is what answers a repeated refresh with the same new token (see Sessions).
 */
interface RefreshSecrets
{
    /**
     * @param string $sessionKey The key part of the session's tokens, as it stands in the token.
     * @param int    $generation Which token of the session: 0, 1, 2, …
     *
     * @return string Unguessable without the server's key, safe in a URL and a cookie, without a
     *                dot. The same for the same arguments, every time.
     */
    public function secret(#[SensitiveParameter] string $sessionKey, int $generation): string;
}
