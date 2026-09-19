<?php

declare(strict_types=1);

namespace Webmention\Tests\Unit\Webmention;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webmention\Controllers\ApiController;
use Webmention\Tests\Support\Subprocess;
use Webmention\Webmention\SafeTransport;

final class SafeTransportTest extends TestCase
{
    /** @var list<Subprocess> */
    private static array $servers = [];

    /** @var list<string> Base URLs of two local origins. */
    private static array $origins = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as $server) {
            $server->stop();
        }
        self::$servers = [];
        self::$origins = [];
        self::setOwnAddresses(null);
    }

    /** @param list<string>|null $addresses Pretend these are this machine's interfaces (null: look them up again). */
    private static function setOwnAddresses(?array $addresses): void
    {
        $property = new \ReflectionProperty(SafeTransport::class, 'ownAddresses');
        $property->setValue(null, $addresses === null ? null : array_map(static fn (string $ip): string => (string) inet_pton($ip), $addresses));
    }

    public function testThisServersOwnPublicAddressIsAllowedOnTheWebPortsOnly(): void
    {
        self::setOwnAddresses(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']);
        $transport = new SafeTransport();

        // Another site on the same server, such as an IndieAuth server, is as reachable as it is for anyone else.
        self::assertNull($transport->blockedReason('https://93.184.216.34/s/abc/metadata'));
        self::assertNull($transport->blockedReason('http://93.184.216.34/'));
        self::assertNull($transport->blockedReason('https://[2606:2800:220:1:248:1893:25c8:1946]/'));

        // Anything else on this box is not.
        self::assertSame("Refusing to connect to this server's own address on port 8080", $transport->blockedReason('http://93.184.216.34:8080/'));
        self::assertSame("Refusing to connect to this server's own address on port 6379", $transport->blockedReason('http://[2606:2800:220:1:248:1893:25c8:1946]:6379/'));
        self::assertSame(SafeTransport::BLOCKED, $transport->get('http://93.184.216.34:8080/')['error']);

        // Loopback and private ranges are refused as before, on any port.
        self::assertSame('Refusing to connect to a non-public address for 127.0.0.1', $transport->blockedReason('http://127.0.0.1/'));
        self::assertSame('Refusing to connect to a non-public address for 10.0.0.5', $transport->blockedReason('https://10.0.0.5/'));

        self::setOwnAddresses(null);
    }

    /** @return iterable<string, array{string}> */
    public static function forbidden(): iterable
    {
        yield 'loopback'           => ['http://127.0.0.1/'];
        yield 'loopback with port' => ['http://127.0.0.1:6379/'];
        yield 'localhost'          => ['http://localhost:8080/x'];
        yield 'trailing dot'       => ['http://localhost./'];
        yield 'cloud metadata'     => ['http://169.254.169.254/latest/meta-data/'];
        yield '10/8'               => ['http://10.1.2.3/'];
        yield '172.16/12'          => ['https://172.20.0.5/'];
        yield '192.168/16'         => ['http://192.168.1.1/'];
        yield 'carrier-grade NAT'  => ['http://100.64.0.1/'];
        yield 'protocol assignments' => ['http://192.0.0.8/'];
        yield 'benchmarking'       => ['http://198.18.0.1/'];
        yield 'multicast'          => ['http://224.0.0.1/'];
        yield 'broadcast'          => ['http://255.255.255.255/'];
        yield 'unspecified'        => ['http://0.0.0.0/'];
        yield 'IPv6 loopback'      => ['http://[::1]/'];
        yield 'IPv6 unique local'  => ['http://[fd00::1]/'];
        yield 'IPv6 link local'    => ['http://[fe80::1]/'];
        yield 'IPv6 site local'    => ['http://[fec0::1]/'];
        yield 'IPv6 multicast'     => ['http://[ff02::1]/'];
        yield 'IPv4-mapped IPv6'   => ['http://[::ffff:127.0.0.1]/'];
        yield 'IPv4-mapped, hex'   => ['http://[::ffff:a9fe:a9fe]/'];
        yield 'NAT64 loopback'     => ['http://[64:ff9b::7f00:1]/'];
        yield 'local NAT64'        => ['http://[64:ff9b:1::1]/'];
        yield '6to4 loopback'      => ['http://[2002:7f00:1::1]/'];
        yield 'Teredo'             => ['http://[2001:0:4136:e378:8000:63bf:3fff:fdd2]/'];
        yield 'documentation'      => ['http://[2001:db8::1]/'];
        yield 'smtp port'          => ['http://8.8.8.8:25/'];
        yield 'ssh port'           => ['http://8.8.8.8:22/'];
        yield 'gopher'             => ['gopher://example.com:6379/_SET%20a%20b'];
        yield 'file'               => ['file:///etc/passwd'];
        yield 'dict'               => ['dict://example.com:11211/stat'];
        yield 'no host'            => ['http:///path'];
    }

    #[DataProvider('forbidden')]
    public function testRefusesNonPublicAddressesAndOtherSchemes(string $url): void
    {
        $transport = new SafeTransport();

        self::assertNotNull($transport->blockedReason($url));

        $response = $transport->get($url);
        self::assertContains($response['error'], [SafeTransport::BLOCKED, 'dns_error']);
        self::assertSame(0, $response['code']);
    }

    public function testPublicAddresses(): void
    {
        self::assertTrue(SafeTransport::publicAddress('93.184.216.34'));
        self::assertTrue(SafeTransport::publicAddress('2606:2800:220:1:248:1893:25c8:1946'));
        self::assertFalse(SafeTransport::publicAddress('100.127.255.255'));
        self::assertTrue(SafeTransport::publicAddress('100.128.0.1'));
        self::assertFalse(SafeTransport::publicAddress('not an ip'));
        self::assertTrue(SafeTransport::publicAddress('::ffff:93.184.216.34'));
        self::assertFalse(SafeTransport::publicAddress('::'));
        // 6to4 is judged by the address it carries.
        self::assertTrue(SafeTransport::publicAddress('2002:5db8:d822::1'));
        self::assertFalse(SafeTransport::publicAddress('2002:0a00:0001::1'));
    }

    public function testWebPortsOnPublicAddressesAreAllowedWithoutDns(): void
    {
        $transport = new SafeTransport();

        self::assertNull($transport->blockedReason('http://8.8.8.8/'));
        self::assertNull($transport->blockedReason('https://8.8.8.8:8443/'));
        self::assertNull($transport->blockedReason('http://8.8.8.8:65535/'));
        self::assertNotNull($transport->blockedReason('http://8.8.8.8:0/'));
        self::assertNotNull($transport->blockedReason('http://8.8.8.8:70000/'));
    }

    public function testPrivateNetworkCanBeAllowedForDevelopment(): void
    {
        self::assertNull((new SafeTransport(allowPrivateNetwork: true))->blockedReason('http://127.0.0.1:8080/'));
        self::assertNotNull((new SafeTransport(allowPrivateNetwork: true))->blockedReason('gopher://127.0.0.1/'));
    }

    public function testRefusesOversizedResponses(): void
    {
        [$origin] = self::origins();
        $transport = new SafeTransport(allowPrivateNetwork: true, maxBytes: 1024 * 1024);
        $transport->set_timeout(10);

        $response = $transport->get("$origin/big?mb=3");

        self::assertSame(SafeTransport::TOO_LARGE, $response['error']);
        self::assertSame('', $response['body']);

        $small = $transport->get("$origin/big?mb=0");
        self::assertSame('', $small['error']);
        self::assertSame(200, $small['code']);
    }

    public function testPostsAreNeverRedirected(): void
    {
        [$origin] = self::origins();
        $transport = new SafeTransport(allowPrivateNetwork: true);

        $response = $transport->post("$origin/redirect?to=/echo", 'secret=1', ['Content-Type: application/x-www-form-urlencoded']);

        self::assertSame('', $response['error']);
        self::assertSame(302, $response['code']);
        self::assertMatchesRegularExpression('/^location: \/echo\r?$/im', $response['header']);
    }

    public function testCredentialsAreDroppedWhenARedirectLeavesTheOrigin(): void
    {
        [$one, $two] = self::origins();
        $transport   = new SafeTransport(allowPrivateNetwork: true);
        $headers     = ['Authorization: Bearer secret', 'Cookie: a=b', 'X-Keep: yes'];

        $crossOrigin = json_decode($transport->get("$one/redirect?to=" . rawurlencode("$two/echo"), $headers)['body'], true);
        self::assertSame('GET', $crossOrigin['method']);
        self::assertArrayNotHasKey('Authorization', $crossOrigin['headers']);
        self::assertArrayNotHasKey('Cookie', $crossOrigin['headers']);
        self::assertSame('yes', $crossOrigin['headers']['X-Keep']);

        $sameOrigin = json_decode($transport->get("$one/redirect?to=/echo", $headers)['body'], true);
        self::assertSame('Bearer secret', $sameOrigin['headers']['Authorization']);
        self::assertSame("$one/echo", $sameOrigin['url'] ?? "$one/echo");
    }

    public function testTheTimeBudgetCoversTheWholeRedirectChain(): void
    {
        [$origin] = self::origins();
        $transport = new SafeTransport(allowPrivateNetwork: true);
        $transport->set_timeout(1);

        // Each hop is well under the per-request timeout; together they are not.
        $last  = "$origin/slow?ms=600";
        $chain = "$origin/slow?ms=600&then=" . rawurlencode("$origin/slow?ms=600&then=" . rawurlencode($last));

        $started  = microtime(true);
        $response = $transport->get($chain);

        self::assertSame('timeout', $response['error'], json_encode($response));
        self::assertLessThan(2.5, microtime(true) - $started);

        self::assertSame(200, $transport->get($last)['code']);
    }

    public function testHugePageNumbersDoNotOverflow(): void
    {
        self::assertSame(PHP_INT_MAX, ApiController::offset(922337203685477581, 20));
        self::assertSame(40, ApiController::offset(2, 20));
        self::assertSame(0, ApiController::offset(-5, 20));
        self::assertSame(0, ApiController::offset(5, 0));
    }

    /**
     * Two local origins (different ports) serving tests/Support/server.php.
     *
     * @return list<string>
     */
    private static function origins(): array
    {
        if (self::$origins !== []) {
            return self::$origins;
        }

        for ($i = 0; $i < 2; $i++) {
            $port            = Subprocess::freePort();
            self::$servers[] = new Subprocess(['php', '-S', "127.0.0.1:$port", 'tests/Support/server.php']);
            self::$origins[] = "http://127.0.0.1:$port";
        }

        foreach (self::$origins as $origin) {
            Subprocess::waitFor(
                static fn (): ?bool => @file_get_contents("$origin/echo") !== false ? true : null,
                10,
                "the test server at $origin",
            );
        }

        return self::$origins;
    }
}
