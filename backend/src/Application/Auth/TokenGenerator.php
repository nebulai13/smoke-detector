<?php

declare(strict_types=1);

namespace SmokeDetector\Application\Auth;

/**
 * Makes the secret tokens: refresh tokens and the tokens in mail links.
 */
interface TokenGenerator
{
    /**
     * @return string 32 bytes from the system's secure random source, as base64url without
     *                padding (43 characters). Unguessable and safe in a URL.
     */
    public function generate(): string;
}
