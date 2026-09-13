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
 * and every redirect, must be http or https to a public address. The address
 * that was checked is pinned for the connection, so DNS can't answer
 * differently between the check and the connect.
 *
 * Responses have the same shape as p3k\HTTP\Curl's.
 */
final class SafeTransport implements Transport
{
    public const BLOCKED = 'forbidden_address';

    private float $timeout = 4;
    private int $maxRedirects = 8;

    public function __construct(private readonly bool $allowPrivateNetwork = false)
    {
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

        // IPv6 forms that carry an IPv4 address (::ffff:a.b.c.d, ::a.b.c.d and
        // NAT64's 64:ff9b::a.b.c.d) are judged by that address.
        if (strlen($packed) === 16) {
            $prefix = substr($packed, 0, 12);
            if ($prefix === str_repeat("\0", 10) . "\xff\xff"
                || $prefix === str_repeat("\0", 12)
                || $prefix === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) {
                return self::publicAddress((string) inet_ntop(substr($packed, 12)));
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // Carrier-grade NAT (100.64.0.0/10), which PHP's filter doesn't cover.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return (ip2long($ip) & 0xFFC00000) !== (ip2long('100.64.0.0') & 0xFFC00000);
        }

        return true;
    }

    /**
     * @param  list<string> $headers
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, ?string $body, array $headers): array
    {
        $redirects = 0;

        while (true) {
            $target = $this->resolve($url);
            if ($target['error'] !== null) {
                return self::failure($url, $target['code'], $target['error']);
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER    => true,
                CURLOPT_HEADER            => true,
                CURLOPT_FOLLOWLOCATION    => false,
                CURLOPT_PROTOCOLS         => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS   => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_TIMEOUT_MS        => (int) round($this->timeout * 1000),
                CURLOPT_CONNECTTIMEOUT_MS => 2000,
                CURLOPT_HTTP_VERSION      => defined('CURL_HTTP_VERSION_2') ? CURL_HTTP_VERSION_2 : CURL_HTTP_VERSION_NONE,
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

            $response   = curl_exec($ch);
            $errno      = curl_errno($ch);
            $code       = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

            if (!is_string($response) || $errno !== 0) {
                return self::failure($url, Curl::error_string_from_code($errno), curl_error($ch), $code);
            }

            $header = trim(substr($response, 0, $headerSize));

            // Redirects are followed here, not by curl, so each hop is checked.
            if (in_array($code, [301, 302, 303, 307, 308], true)
                && $this->maxRedirects > 0
                && preg_match_all('/^location:\s*(\S.*)$/im', $header, $m) > 0) {
                if ($redirects >= $this->maxRedirects) {
                    return self::failure($url, 'too_many_redirects', 'Too many redirects', $code);
                }
                $redirects++;
                $url = \Mf2\resolveUrl($url, trim((string) end($m[1])));
                continue;
            }

            return [
                'code'              => $code,
                'header'            => $header,
                'body'              => substr($response, $headerSize),
                'error'             => '',
                'error_description' => '',
                'url'               => $url,
                'debug'             => $response,
            ];
        }
    }

    /**
     * @return array{error: string|null, code: string, pin: string|null}
     */
    private function resolve(string $url): array
    {
        $parts  = parse_url(Url::escape($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = trim((string) ($parts['host'] ?? ''), '[]');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return ['error' => 'Only http and https URLs can be fetched', 'code' => self::BLOCKED, 'pin' => null];
        }

        if ($this->allowPrivateNetwork) {
            return ['error' => null, 'code' => '', 'pin' => null];
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
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
            return ['error' => "Could not resolve host: $host", 'code' => 'dns_error', 'pin' => null];
        }

        foreach ($addresses as $address) {
            if (!self::publicAddress($address)) {
                return ['error' => "Refusing to connect to a non-public address for $host", 'code' => self::BLOCKED, 'pin' => null];
            }
        }

        $address = $addresses[0];
        $pinned  = str_contains($address, ':') ? "[$address]" : $address;

        return ['error' => null, 'code' => '', 'pin' => "$host:$port:$pinned"];
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
