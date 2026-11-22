<?php

declare(strict_types=1);

namespace SmokeDetector\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The address a request is rate-limited under (HLD §4.5 "per IP").
 *
 * It is the address of the TCP connection as the web server reports it (REMOTE_ADDR), and nothing
 * else. X-Forwarded-For and its relatives are request headers: whoever sends the request writes
 * them, so trusting them would hand every client as many addresses as it likes. Should the hosting
 * ever put a proxy in front of PHP, REMOTE_ADDR becomes the proxy's address and all clients share
 * one counter: that fails closed (too strict) instead of open, and must then be solved by naming
 * the trusted proxy explicitly.
 */
final readonly class ClientAddress
{
    /** When the server reports no usable address. All such requests share one counter. */
    public const string UNKNOWN = 'unknown';

    private const string IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xFF\xFF";

    /**
     * @return string An IPv4 address, or the /64 network of an IPv6 address ("2001:db8:1:2::/64"),
     *                because one subscriber owns a whole such network and could otherwise take a new
     *                address for every attempt.
     */
    public static function of(ServerRequestInterface $request): string
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return self::UNKNOWN;
        }

        $packed = inet_pton($address);
        if ($packed === false) {
            return self::UNKNOWN;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, self::IPV4_MAPPED_PREFIX)) {
            $packed = substr($packed, 12);
        }

        $printed = inet_ntop(strlen($packed) === 4 ? $packed : substr($packed, 0, 8) . str_repeat("\0", 8));
        if ($printed === false) {
            return self::UNKNOWN;
        }

        return strlen($packed) === 4 ? $printed : $printed . '/64';
    }
}
