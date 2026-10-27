<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Http;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Http\ClientIp;

#[CoversClass(ClientIp::class)]
final class ClientIpTest extends TestCase
{
    private static function request(string $remote, ?string $forwardedFor = null): ServerRequest
    {
        $headers = $forwardedFor === null ? [] : ['X-Forwarded-For' => $forwardedFor];

        return new ServerRequest('GET', '/', $headers, null, '1.1', ['REMOTE_ADDR' => $remote]);
    }

    public function testUsesRemoteAddrWithoutTrustedProxies(): void
    {
        self::assertSame('203.0.113.7', (new ClientIp())->of(self::request('203.0.113.7')));
    }

    public function testIgnoresForwardedForFromUntrustedPeer(): void
    {
        // A client sending its own header must not be able to pick its IP.
        $ip = new ClientIp(['10.0.0.0/8']);

        self::assertSame('203.0.113.7', $ip->of(self::request('203.0.113.7', '1.2.3.4')));
    }

    public function testUsesForwardedForFromTrustedProxy(): void
    {
        $ip = new ClientIp(['10.0.0.0/8']);

        self::assertSame('198.51.100.9', $ip->of(self::request('10.1.2.3', '198.51.100.9')));
    }

    public function testReadsChainFromTheRightSkippingTrustedHops(): void
    {
        $ip = new ClientIp(['10.0.0.0/8', '192.168.1.5']);

        // The client prepended a fake address; the real one is the last untrusted hop.
        $request = self::request('10.0.0.1', '1.1.1.1, 198.51.100.9, 192.168.1.5, 10.0.0.2');

        self::assertSame('198.51.100.9', $ip->of($request));
    }

    public function testFallsBackToRemoteWhenHeaderIsMissingMalformedOrAllTrusted(): void
    {
        $ip = new ClientIp(['10.0.0.0/8']);

        self::assertSame('10.0.0.1', $ip->of(self::request('10.0.0.1')));
        self::assertSame('10.0.0.1', $ip->of(self::request('10.0.0.1', '198.51.100.9, not-an-ip')));
        self::assertSame('10.0.0.1', $ip->of(self::request('10.0.0.1', '10.0.0.5, 10.0.0.6')));
    }

    public function testInvalidRemoteAddrBecomesUnspecified(): void
    {
        self::assertSame('0.0.0.0', (new ClientIp())->of(self::request('garbage')));
        self::assertSame('0.0.0.0', (new ClientIp())->of(new ServerRequest('GET', '/')));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function ranges(): iterable
    {
        yield 'exact v4' => ['127.0.0.1', '127.0.0.1', true];
        yield 'exact v4 other' => ['127.0.0.2', '127.0.0.1', false];
        yield '/8 inside' => ['10.255.0.1', '10.0.0.0/8', true];
        yield '/8 outside' => ['11.0.0.1', '10.0.0.0/8', false];
        yield '/12 inside' => ['172.31.255.255', '172.16.0.0/12', true];
        yield '/12 outside' => ['172.32.0.0', '172.16.0.0/12', false];
        yield '/0 matches all v4' => ['8.8.8.8', '0.0.0.0/0', true];
        yield '/32' => ['192.0.2.1', '192.0.2.1/32', true];
        yield 'v6 exact' => ['::1', '::1', true];
        yield 'v6 /32 inside' => ['2001:db8:ffff::1', '2001:db8::/32', true];
        yield 'v6 /32 outside' => ['2001:db9::1', '2001:db8::/32', false];
        yield 'v6 /47 bit boundary' => ['2001:db8:1::1', '2001:db8::/47', true];
        yield 'v6 /48 bit boundary' => ['2001:db8:1::1', '2001:db8::/48', false];
        yield 'v4 vs v6 range' => ['127.0.0.1', '::1', false];
        yield 'bad prefix' => ['10.0.0.1', '10.0.0.0/33', false];
        yield 'non-numeric prefix' => ['10.0.0.1', '10.0.0.0/x', false];
        yield 'bad subnet' => ['10.0.0.1', 'nope/8', false];

        // IPv4 prefixes and the addresses on either side of each boundary.
        yield 'v4 /0 lowest' => ['0.0.0.0', '0.0.0.0/0', true];
        yield 'v4 /0 highest' => ['255.255.255.255', '0.0.0.0/0', true];
        yield 'v4 /8 first' => ['10.0.0.0', '10.0.0.0/8', true];
        yield 'v4 /8 last' => ['10.255.255.255', '10.0.0.0/8', true];
        yield 'v4 /8 one below' => ['9.255.255.255', '10.0.0.0/8', false];
        yield 'v4 /24 first' => ['192.168.1.0', '192.168.1.0/24', true];
        yield 'v4 /24 last' => ['192.168.1.255', '192.168.1.0/24', true];
        yield 'v4 /24 one above' => ['192.168.2.0', '192.168.1.0/24', false];
        yield 'v4 /24 one below' => ['192.168.0.255', '192.168.1.0/24', false];
        yield 'v4 /25 lower half' => ['192.168.1.127', '192.168.1.0/25', true];
        yield 'v4 /25 upper half' => ['192.168.1.128', '192.168.1.0/25', false];
        yield 'v4 /25 upper network' => ['192.168.1.200', '192.168.1.128/25', true];
        yield 'v4 /31 pair low' => ['203.0.113.6', '203.0.113.6/31', true];
        yield 'v4 /31 pair high' => ['203.0.113.7', '203.0.113.6/31', true];
        yield 'v4 /31 next pair' => ['203.0.113.8', '203.0.113.6/31', false];
        yield 'v4 /32 other' => ['192.0.2.2', '192.0.2.1/32', false];
        yield 'v4 host bits set in network' => ['10.1.2.3', '10.9.9.9/8', true];
        yield 'v4 leading-zero prefix' => ['10.0.0.1', '10.0.0.0/08', true];

        // IPv6.
        yield 'v6 /0' => ['2001:db8::1', '::/0', true];
        yield 'v6 /48 first' => ['2001:db8:abcd::', '2001:db8:abcd::/48', true];
        yield 'v6 /48 last' => ['2001:db8:abcd:ffff:ffff:ffff:ffff:ffff', '2001:db8:abcd::/48', true];
        yield 'v6 /48 one above' => ['2001:db8:abce::', '2001:db8:abcd::/48', false];
        yield 'v6 /64 inside' => ['fe80::1234:5678', 'fe80::/64', true];
        yield 'v6 /64 outside' => ['fe80:0:0:1::1', 'fe80::/64', false];
        yield 'v6 /127 pair' => ['2001:db8::1', '2001:db8::/127', true];
        yield 'v6 /127 next' => ['2001:db8::2', '2001:db8::/127', false];
        yield 'v6 /128' => ['2001:db8::1', '2001:db8::1/128', true];
        yield 'v6 /128 other' => ['2001:db8::2', '2001:db8::1/128', false];
        yield 'v6 /33 non-aligned' => ['2001:db8:7fff::1', '2001:db8::/33', true];
        yield 'v6 /33 non-aligned outside' => ['2001:db8:8000::1', '2001:db8::/33', false];
        yield 'v6 notation does not matter' => ['2001:0DB8:0000::0001', '2001:db8::1', true];
        yield 'v6 /129' => ['2001:db8::1', '2001:db8::/129', false];

        // Families are never mixed, including IPv4-mapped IPv6.
        yield 'v6 address vs v4 range' => ['::1', '127.0.0.1', false];
        yield 'v4-mapped v6 vs v4 range' => ['::ffff:10.0.0.1', '10.0.0.0/8', false];
        yield 'v4-mapped v6 vs mapped range' => ['::ffff:10.0.0.1', '::ffff:10.0.0.0/104', true];
        yield 'v4 vs /0 v6' => ['10.0.0.1', '::/0', false];

        // Malformed input.
        yield 'trailing slash' => ['10.0.0.1', '10.0.0.0/', false];
        yield 'negative prefix' => ['10.0.0.1', '10.0.0.0/-1', false];
        yield 'prefix with sign' => ['10.0.0.1', '10.0.0.0/+8', false];
        yield 'prefix with space' => ['10.0.0.1', '10.0.0.0/ 8', false];
        yield 'second slash' => ['10.0.0.1', '10.0.0.0/8/8', false];
        yield 'huge prefix' => ['10.0.0.1', '10.0.0.0/99999999999999999999', false];
        yield 'empty range' => ['10.0.0.1', '', false];
        yield 'only slash' => ['10.0.0.1', '/8', false];
        yield 'invalid ip' => ['10.0.0.256', '10.0.0.0/8', false];
        yield 'empty ip' => ['', '0.0.0.0/0', false];
        yield 'short v4 range' => ['10.0.0.1', '10.0/8', false];
        yield 'hostname range' => ['127.0.0.1', 'localhost', false];
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function rateLimitKeys(): iterable
    {
        yield 'ipv4 unchanged' => ['203.0.113.7', 64, '203.0.113.7'];
        yield 'ipv6 /64' => ['2001:db8:1:2:aaaa:bbbb:cccc:dddd', 64, '2001:db8:1:2::/64'];
        yield 'ipv6 /64 other host, same key' => ['2001:db8:1:2::1', 64, '2001:db8:1:2::/64'];
        yield 'ipv6 /48' => ['2001:db8:1:2::1', 48, '2001:db8:1::/48'];
        yield 'ipv6 /60 not byte-aligned' => ['2001:db8:1:2ff::1', 60, '2001:db8:1:2f0::/60'];
        yield 'ipv6 /128 unchanged' => ['2001:db8::1', 128, '2001:db8::1'];
        yield 'ipv6 /1' => ['ffff::1', 1, '8000::/1'];
        yield 'ipv4-mapped is ipv6' => ['::ffff:203.0.113.7', 64, '::/64'];
        yield 'not an address unchanged' => ['0.0.0.0', 64, '0.0.0.0'];
    }

    #[DataProvider('rateLimitKeys')]
    public function testRateLimitKeys(string $ip, int $prefix, string $expected): void
    {
        self::assertSame($expected, ClientIp::forRateLimit($ip, $prefix));
    }

    public function testRateLimitPrefixMustBeValid(): void
    {
        foreach ([0, 129, -1] as $prefix) {
            try {
                ClientIp::forRateLimit('2001:db8::1', $prefix);
                self::fail('prefix ' . $prefix . ' should be rejected');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[DataProvider('ranges')]
    public function testCidrMatching(string $ip, string $range, bool $expected): void
    {
        self::assertSame($expected, (new ClientIp([$range]))->isTrusted($ip));
    }
}
