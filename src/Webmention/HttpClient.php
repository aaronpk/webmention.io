<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use p3k\HTTP;
use p3k\HTTP\Transport;
use p3k\XRay;

/**
 * Makes every outgoing HTTP client, so they share a user agent and tests can
 * swap the transport for one that never touches the network.
 */
final class HttpClient
{
    /** XRay's own browser-like user agent; some sites serve bots different markup. */
    private const BASE_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/76.0.3809.132 Safari/537.36 p3k/XRay';

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?Transport $transport = null,
    ) {
    }

    public function userAgent(): string
    {
        return self::BASE_USER_AGENT . ' webmention.io (+' . $this->baseUrl . ')';
    }

    public function http(int $timeout = 20): HTTP
    {
        $http = $this->transport === null
            ? new HTTP($this->userAgent())
            // XRay's fetcher swaps in a Curl transport for every request, which
            // would bypass an injected one, so only the constructor's call counts.
            : new class($this->userAgent(), $this->transport) extends HTTP {
                private bool $pinned = false;

                public function set_transport(Transport $transport)
                {
                    if (!$this->pinned) {
                        $this->pinned = true;
                        parent::set_transport($transport);
                    }
                }
            };

        $http->set_timeout($timeout);

        return $http;
    }

    public function xray(int $timeout): XRay
    {
        $xray       = new XRay(['timeout' => $timeout]);
        $xray->http = $this->http($timeout);

        return $xray;
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
