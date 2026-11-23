<?php

declare(strict_types=1);

namespace SmokeDetector\Tests\Unit\Http;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmokeDetector\Http\ClientAddress;

/**
 * Which address a request is rate-limited under (HLD §4.5 "per IP", NFR-05).
 */
final class ClientAddressTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function remoteAddresses(): iterable
    {
        yield 'IPv4' => ['203.0.113.7', '203.0.113.7'];
        yield 'IPv4 loopback' => ['127.0.0.1', '127.0.0.1'];
        // One subscriber has a whole /64 (or more) to pick addresses from: they share one counter.
        yield 'IPv6' => ['2001:db8:1:2:aaaa:bbbb:cccc:dddd', '2001:db8:1:2::/64'];
        yield 'IPv6, another host of the same network' => ['2001:db8:1:2::1', '2001:db8:1:2::/64'];
        yield 'IPv6, written differently' => ['2001:0DB8:0001:0002:0000:0000:0000:0001', '2001:db8:1:2::/64'];
        yield 'IPv6, the next network' => ['2001:db8:1:3::1', '2001:db8:1:3::/64'];
        yield 'IPv6 loopback' => ['::1', '::/64'];
        // The same client must not get a second counter by arriving as "IPv4 in IPv6".
        yield 'IPv4-mapped IPv6' => ['::ffff:203.0.113.7', '203.0.113.7'];
        yield 'IPv4-mapped IPv6 in hex' => ['::ffff:cb00:7107', '203.0.113.7'];
        yield 'missing' => [null, 'unknown'];
        yield 'empty' => ['', 'unknown'];
        yield 'not an address' => ['localhost', 'unknown'];
        yield 'with a port' => ['203.0.113.7:443', 'unknown'];
        yield 'with a zone' => ['fe80::1%eth0', 'unknown'];
        yield 'a list of addresses' => ['203.0.113.7, 198.51.100.1', 'unknown'];
        yield 'not a string' => [['203.0.113.7'], 'unknown'];
        yield 'with space' => [' 203.0.113.7', 'unknown'];
    }

    #[DataProvider('remoteAddresses')]
    public function test_NFR_05_the_client_address_is_the_address_of_the_connection(mixed $remoteAddress, string $expected): void
    {
        $request = new ServerRequest('POST', '/api/v1/auth/login', [], null, '1.1', $remoteAddress === null ? [] : ['REMOTE_ADDR' => $remoteAddress]);

        self::assertSame($expected, ClientAddress::of($request));
    }

    public function test_NFR_05_headers_a_client_can_write_itself_are_never_trusted(): void
    {
        // Otherwise every request could claim a fresh address and the limit would never bite.
        $request = new ServerRequest(
            'POST',
            '/api/v1/auth/login',
            [
                'X-Forwarded-For' => '198.51.100.1',
                'Forwarded' => 'for=198.51.100.1',
                'X-Real-IP' => '198.51.100.1',
                'Client-IP' => '198.51.100.1',
                'CF-Connecting-IP' => '198.51.100.1',
            ],
            null,
            '1.1',
            ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1', 'HTTP_CLIENT_IP' => '198.51.100.1'],
        );

        self::assertSame('203.0.113.7', ClientAddress::of($request));

        $withoutRemoteAddress = new ServerRequest('POST', '/x', ['X-Forwarded-For' => '198.51.100.1'], null, '1.1', ['HTTP_X_FORWARDED_FOR' => '198.51.100.1']);
        self::assertSame('unknown', ClientAddress::of($withoutRemoteAddress));
    }
}
