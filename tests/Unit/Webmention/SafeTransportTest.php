<?php

declare(strict_types=1);

namespace Webmention\Tests\Unit\Webmention;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webmention\Controllers\ApiController;
use Webmention\Webmention\SafeTransport;

final class SafeTransportTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function forbidden(): iterable
    {
        yield 'loopback'           => ['http://127.0.0.1/'];
        yield 'loopback with port' => ['http://127.0.0.1:6379/'];
        yield 'localhost'          => ['http://localhost:8080/x'];
        yield 'cloud metadata'     => ['http://169.254.169.254/latest/meta-data/'];
        yield '10/8'               => ['http://10.1.2.3/'];
        yield '172.16/12'          => ['https://172.20.0.5/'];
        yield '192.168/16'         => ['http://192.168.1.1/'];
        yield 'carrier-grade NAT'  => ['http://100.64.0.1/'];
        yield 'unspecified'        => ['http://0.0.0.0/'];
        yield 'IPv6 loopback'      => ['http://[::1]/'];
        yield 'IPv6 unique local'  => ['http://[fd00::1]/'];
        yield 'IPv6 link local'    => ['http://[fe80::1]/'];
        yield 'IPv4-mapped IPv6'   => ['http://[::ffff:127.0.0.1]/'];
        yield 'IPv4-mapped, hex'   => ['http://[::ffff:a9fe:a9fe]/'];
        yield 'NAT64 loopback'     => ['http://[64:ff9b::7f00:1]/'];
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
    }

    public function testPrivateNetworkCanBeAllowedForDevelopment(): void
    {
        self::assertNull((new SafeTransport(allowPrivateNetwork: true))->blockedReason('http://127.0.0.1:8080/'));
        self::assertNotNull((new SafeTransport(allowPrivateNetwork: true))->blockedReason('gopher://127.0.0.1/'));
    }

    public function testHugePageNumbersDoNotOverflow(): void
    {
        self::assertSame(PHP_INT_MAX, ApiController::offset(922337203685477581, 20));
        self::assertSame(40, ApiController::offset(2, 20));
        self::assertSame(0, ApiController::offset(-5, 20));
        self::assertSame(0, ApiController::offset(5, 0));
    }
}
