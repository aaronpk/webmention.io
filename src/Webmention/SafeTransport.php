<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use p3k\HTTP\Curl;
use p3k\HTTP\Transport;
use Webmention\Format\Url;

/**
 * The HTTP transport for every outgoing request.
 *
 * Webmention sources, token endpoints and callback URLs are chosen by
 * strangers, and this runs next to Redis and the database. So every request,
 * and every redirect, must be http or https, on a web port, to a public
 * address. This machine's own public address is allowed on ports 80 and 443
 * only: there it is the web server anyone on the internet can reach, and
 * other sites hosted on the same server (an IndieAuth server, say) must stay
 * reachable; on any other port it could be an internal service, so it is
 * refused. The address that was checked is pinned for the connection, so DNS
 * can't answer differently between the check and the connect, and the
 * address curl actually connected to is checked again afterwards.
 *
 * Responses are bounded: a body larger than maxBytes, or a transfer that
 * overruns its time budget across all redirects, is a failure. POST and PUT
 * never follow redirects, and a GET that is redirected to another origin
 * loses its Authorization and Cookie headers, so no credential is ever
 * replayed to a host the caller did not name.
 *
 * Responses have the same shape as p3k\HTTP\Curl's.
 */
final class SafeTransport implements Transport
{
    public const BLOCKED   = 'forbidden_address';
    public const TOO_LARGE = 'response_too_large';

    /** Ports below this must be 80 or 443. */
    private const FIRST_UNPRIVILEGED_PORT = 1024;

    private const MAX_HEADER_BYTES = 65536;

    /**
     * IANA special-purpose address blocks that are never a public web server,
     * plus multicast and the reserved class E space. Blocks that embed an IPv4
     * address (IPv4-mapped, IPv4-compatible, NAT64, 6to4) are unwrapped in
     * publicAddress() and judged by the embedded address instead.
     */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',       // "this" network
        '10.0.0.0/8',      // private
        '100.64.0.0/10',   // carrier-grade NAT
        '127.0.0.0/8',     // loopback
        '169.254.0.0/16',  // link-local, cloud metadata
        '172.16.0.0/12',   // private
        '192.0.0.0/24',    // IETF protocol assignments
        '192.0.2.0/24',    // TEST-NET-1
        '192.88.99.0/24',  // 6to4 relay anycast (deprecated)
        '192.168.0.0/16',  // private
        '198.18.0.0/15',   // benchmarking
        '198.51.100.0/24', // TEST-NET-2
        '203.0.113.0/24',  // TEST-NET-3
        '224.0.0.0/4',     // multicast
        '240.0.0.0/4',     // reserved, includes the broadcast address
    ];

    private const BLOCKED_V6 = [
        '::/128',          // unspecified
        '::1/128',         // loopback
        '64:ff9b:1::/48',  // local-use NAT64
        '100::/64',        // discard-only
        '2001::/32',       // Teredo (tunnels to a client behind NAT)
        '2001:2::/48',     // benchmarking
        '2001:db8::/32',   // documentation
        '3fff::/20',       // documentation
        '5f00::/16',       // segment routing
        'fc00::/7',        // unique local
        'fe80::/10',       // link-local
        'fec0::/10',       // site-local (deprecated)
        'ff00::/8',        // multicast
    ];

    private float $timeout = 4;
    private int $maxRedirects = 8;
    private int $maxBytes;

    /** @var list<string>|null This machine's own addresses, packed. */
    private static ?array $ownAddresses = null;

    public function __construct(
        private readonly bool $allowPrivateNetwork = false,
        int $maxBytes = 2 * 1024 * 1024,
    ) {
        $this->maxBytes = $maxBytes;
    }

    public function set_timeout($timeout)
    {
        $this->timeout = (float) $timeout;
    }

    public function set_max_redirects($max_redirects)
    {
        $this->maxRedirects = (int) $max_redirects;
    }

    public function get($url, $headers = [])
    {
        return $this->request('GET', (string) $url, null, $headers);
    }

    public function post($url, $body, $headers = [])
    {
        return $this->request('POST', (string) $url, (string) $body, $headers);
    }

    public function put($url, $body, $headers = [])
    {
        return $this->request('PUT', (string) $url, (string) $body, $headers);
    }

    public function head($url, $headers = [])
    {
        $response = $this->request('HEAD', (string) $url, null, $headers);
        unset($response['body']);

        return $response;
    }

    /**
     * Why a URL may not be fetched, or null if it may. Resolves the host.
     */
    public function blockedReason(string $url): ?string
    {
        return $this->resolve($url)['error'];
    }

    /** True for an address on the public internet. */
    public static function publicAddress(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16) {
            $embedded = self::embeddedIPv4($packed);
            if ($embedded !== null) {
                return self::publicAddress((string) inet_ntop($embedded));
            }

            foreach (self::BLOCKED_V6 as $cidr) {
                if (self::inCidr($packed, $cidr)) {
                    return false;
                }
            }

            return true;
        }

        foreach (self::BLOCKED_V4 as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The IPv4 address carried inside an IPv6 one, for the transition forms:
     * ::ffff:a.b.c.d, ::a.b.c.d, NAT64's 64:ff9b::a.b.c.d and 6to4's
     * 2002:AABB:CCDD::. Null for a native IPv6 address.
     */
    private static function embeddedIPv4(string $packed): ?string
    {
        $prefix12 = substr($packed, 0, 12);

        if ($prefix12 === str_repeat("\0", 10) . "\xff\xff"
            || $prefix12 === str_repeat("\0", 12)
            || $prefix12 === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) {
            // ::/96 also contains :: itself, which is not an address at all.
            return substr($packed, 12) === "\0\0\0\0" && $prefix12 === str_repeat("\0", 12) ? null : substr($packed, 12);
        }

        if (substr($packed, 0, 2) === "\x20\x02") {
            return substr($packed, 2, 4);
        }

        return null;
    }

    /** @param string $packed The address from inet_pton. */
    private static function inCidr(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $networkPacked    = (string) inet_pton($network);
        $bits             = (int) $bits;

        if (strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if (substr($packed, 0, $fullBytes) !== substr($networkPacked, 0, $fullBytes)) {
            return false;
        }

        $remaining = $bits % 8;
        if ($remaining === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (ord($packed[$fullBytes]) & $mask) === (ord($networkPacked[$fullBytes]) & $mask);
    }

    /**
     * @param  list<string> $headers
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, ?string $body, array $headers): array
    {
        // The budget covers every hop, so a chain of slow redirects can't
        // multiply the timeout.
        $deadline  = microtime(true) + $this->timeout * 1.5;
        $redirects = 0;
        $origin    = null;

        while (true) {
            $target = $this->resolve($url);
            if ($target['error'] !== null) {
                return self::failure($url, $target['code'], $target['error']);
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return self::failure($url, 'timeout', 'The request took too long');
            }

            if ($origin !== null && $target['origin'] !== $origin) {
                $headers = self::withoutCredentials($headers);
            }
            $origin ??= $target['origin'];

            $headerBuffer = '';
            $bodyBuffer   = '';
            $tooLarge     = false;
            $maxBytes     = $this->maxBytes;

            $ch = curl_init($target['url']);
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION    => false,
                CURLOPT_PROTOCOLS         => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS   => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_PROXY             => '',
                CURLOPT_TIMEOUT_MS        => (int) round(min($this->timeout, $remaining) * 1000),
                CURLOPT_CONNECTTIMEOUT_MS => 2000,
                CURLOPT_LOW_SPEED_LIMIT   => 1024,
                CURLOPT_LOW_SPEED_TIME    => 5,
                CURLOPT_MAXFILESIZE_LARGE => $maxBytes,
                CURLOPT_HTTP_VERSION      => defined('CURL_HTTP_VERSION_2') ? CURL_HTTP_VERSION_2 : CURL_HTTP_VERSION_NONE,
                CURLOPT_HEADERFUNCTION    => static function ($ch, string $line) use (&$headerBuffer): int {
                    if (strlen($headerBuffer) + strlen($line) > self::MAX_HEADER_BYTES) {
                        return 0;
                    }
                    $headerBuffer .= $line;

                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$bodyBuffer, &$tooLarge, $maxBytes): int {
                    if (strlen($bodyBuffer) + strlen($data) > $maxBytes) {
                        $tooLarge = true;

                        return 0;
                    }
                    $bodyBuffer .= $data;

                    return strlen($data);
                },
            ]);

            if ($target['pin'] !== null) {
                curl_setopt($ch, CURLOPT_RESOLVE, [$target['pin']]);
            }

            match ($method) {
                'HEAD'  => curl_setopt($ch, CURLOPT_NOBODY, true),
                'POST'  => curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]),
                'PUT'   => curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $body]),
                default => null,
            };

            if ($headers !== []) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            curl_exec($ch);
            $errno   = curl_errno($ch);
            $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $primary = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);

            if ($tooLarge || $errno === CURLE_FILESIZE_EXCEEDED) {
                return self::failure($url, self::TOO_LARGE, sprintf('The response was larger than %d bytes', $maxBytes), $code);
            }

            if ($errno !== 0) {
                return self::failure($url, Curl::error_string_from_code($errno), curl_error($ch), $code);
            }

            // Belt and braces: whatever curl made of the URL, it must have
            // connected to the address that was checked.
            if ($target['address'] !== null && $primary !== '' && !self::sameAddress($primary, $target['address'])) {
                return self::failure($url, self::BLOCKED, "Connected to $primary instead of the checked address", $code);
            }

            $header = trim($headerBuffer);

            if (in_array($code, [301, 302, 303, 307, 308], true)
                && $this->maxRedirects > 0
                && preg_match_all('/^location:\s*(\S.*)$/im', $header, $m) > 0) {
                // A POST carries a body and often a credential. It goes to the
                // URL the caller named, or nowhere; the caller sees the 3xx.
                if ($method === 'POST' || $method === 'PUT') {
                    return self::response($code, $header, $bodyBuffer, $url);
                }
                if ($redirects >= $this->maxRedirects) {
                    return self::failure($url, 'too_many_redirects', 'Too many redirects', $code);
                }
                $redirects++;
                $url = \Mf2\resolveUrl($url, trim((string) end($m[1])));
                continue;
            }

            return self::response($code, $header, $bodyBuffer, $url);
        }
    }

    /**
     * @return array{error: string|null, code: string, pin: string|null, url: string, address: string|null, origin: string|null}
     */
    private function resolve(string $url): array
    {
        $blocked = static fn (string $why, string $code = self::BLOCKED): array => [
            'error' => $why, 'code' => $code, 'pin' => null, 'url' => $url, 'address' => null, 'origin' => null,
        ];

        $parts  = parse_url(Url::escape($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower(rtrim(trim((string) ($parts['host'] ?? ''), '[]'), '.'));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return $blocked('Only http and https URLs can be fetched');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            return $blocked("Port $port is not valid");
        }

        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;

        // The URL curl gets is rebuilt from the parts that were checked, so no
        // difference between its parser and PHP's can point it elsewhere.
        $rebuilt = $scheme . '://'
            . (isset($parts['user']) ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@' : '')
            . ($isIp && str_contains($host, ':') ? "[$host]" : $host)
            . (isset($parts['port']) ? ':' . $port : '')
            . ($parts['path'] ?? '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $origin = "$scheme://$host:$port";

        if ($this->allowPrivateNetwork) {
            return ['error' => null, 'code' => '', 'pin' => null, 'url' => $rebuilt, 'address' => null, 'origin' => $origin];
        }

        if ($port !== 80 && $port !== 443 && $port < self::FIRST_UNPRIVILEGED_PORT) {
            return $blocked("Refusing to connect to port $port");
        }

        if ($isIp) {
            $addresses = [$host];
        } else {
            $addresses = gethostbynamel($host) ?: [];
            $records   = @dns_get_record($host, DNS_AAAA) ?: [];
            foreach ($records as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        if ($addresses === []) {
            return $blocked("Could not resolve host: $host", 'dns_error');
        }

        foreach ($addresses as $address) {
            if (!self::publicAddress($address)) {
                return $blocked("Refusing to connect to a non-public address for $host");
            }
            if (self::isOwnAddress($address) && $port !== 80 && $port !== 443) {
                return $blocked("Refusing to connect to this server's own address on port $port");
            }
        }

        $address = $addresses[0];

        // A literal IP has nothing to rebind, and curl's resolve entries can't
        // name one anyway.
        $pin = $isIp ? null : "$host:$port:" . (str_contains($address, ':') ? "[$address]" : $address);

        return ['error' => null, 'code' => '', 'pin' => $pin, 'url' => $rebuilt, 'address' => $address, 'origin' => $origin];
    }

    /** Whether an address belongs to one of this machine's own interfaces. */
    private static function isOwnAddress(string $ip): bool
    {
        if (self::$ownAddresses === null) {
            self::$ownAddresses = [];
            $interfaces = function_exists('net_get_interfaces') ? (@net_get_interfaces() ?: []) : [];
            foreach ($interfaces as $interface) {
                foreach ($interface['unicast'] ?? [] as $unicast) {
                    $packed = @inet_pton((string) ($unicast['address'] ?? ''));
                    if ($packed !== false) {
                        self::$ownAddresses[] = $packed;
                    }
                }
            }
        }

        $packed = @inet_pton($ip);

        return $packed !== false && in_array($packed, self::$ownAddresses, true);
    }

    private static function sameAddress(string $a, string $b): bool
    {
        $pa = @inet_pton($a);
        $pb = @inet_pton($b);

        if ($pa === false || $pb === false) {
            return $a === $b;
        }

        // curl reports an IPv4 connection over an IPv6 socket in mapped form.
        if (strlen($pa) === 16 && ($e = self::embeddedIPv4($pa)) !== null) {
            $pa = $e;
        }
        if (strlen($pb) === 16 && ($e = self::embeddedIPv4($pb)) !== null) {
            $pb = $e;
        }

        return $pa === $pb;
    }

    /**
     * @param  list<string> $headers
     * @return list<string>
     */
    private static function withoutCredentials(array $headers): array
    {
        return array_values(array_filter(
            $headers,
            static fn (string $header): bool => preg_match('/^(authorization|cookie|proxy-authorization)\s*:/i', $header) !== 1,
        ));
    }

    /** @return array<string, mixed> */
    private static function response(int $code, string $header, string $body, string $url): array
    {
        return [
            'code'              => $code,
            'header'            => $header,
            'body'              => $body,
            'error'             => '',
            'error_description' => '',
            'url'               => $url,
            'debug'             => '',
        ];
    }

    /** @return array<string, mixed> */
    private static function failure(string $url, string $error, string $description, int $code = 0): array
    {
        return [
            'code'              => $code,
            'header'            => '',
            'body'              => '',
            'error'             => $error,
            'error_description' => $description,
            'url'               => $url,
            'debug'             => '',
        ];
    }
}
