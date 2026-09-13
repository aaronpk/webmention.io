<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use p3k\HTTP\Transport;

/**
 * Makes every outgoing HTTP client. They share a user agent and one transport:
 * SafeTransport in production, a fake one in tests.
 */
final class HttpClient
{
    /** XRay's own browser-like user agent; some sites serve bots different markup. */
    private const BASE_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/76.0.3809.132 Safari/537.36 p3k/XRay';

    private readonly Transport $transport;

    public function __construct(
        private readonly string $baseUrl,
        ?Transport $transport = null,
        bool $allowPrivateNetwork = false,
    ) {
        $this->transport = $transport ?? new SafeTransport($allowPrivateNetwork);
    }

    public function userAgent(): string
    {
        return self::BASE_USER_AGENT . ' webmention.io (+' . $this->baseUrl . ')';
    }

    public function http(int $timeout = 20): PinnedHttp
    {
        $http = new PinnedHttp($this->userAgent(), $this->transport);
        $http->set_timeout($timeout);

        return $http;
    }

    /**
     * POST a JSON body. Returns p3k\HTTP's response array (code, body, error, …).
     *
     * @param array<string, mixed>|object $data
     * @param list<string>                $headers Extra "Name: value" lines.
     * @return array<string, mixed>
     */
    public function postJson(string $url, array|object $data, array $headers = [], string $contentType = 'application/json', int $timeout = 20): array
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';

        return $this->http($timeout)->post($url, $body, ['Content-Type: ' . $contentType, ...$headers]);
    }

    /** @param array<string, mixed> $response */
    public static function succeeded(array $response): bool
    {
        return empty($response['error']) && (int) ($response['code'] ?? 0) >= 200 && (int) ($response['code'] ?? 0) < 300;
    }
}
