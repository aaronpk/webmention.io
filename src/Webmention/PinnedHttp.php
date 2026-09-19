<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use p3k\HTTP;
use p3k\HTTP\Transport;

/**
 * p3k\HTTP bound to one transport for its whole life.
 *
 * XRay's fetcher swaps in its own Curl transport for every request, which
 * would bypass SafeTransport (and the fake transport in tests), so only the
 * constructor's call to set_transport() counts.
 *
 * It also remembers the status and final URL (after redirects) of the first
 * GET, which is the page XRay was asked to parse. XRay doesn't report either
 * for every outcome. And it remembers how the most recent request failed,
 * for callers (the IndieAuth client) that only say a body was unusable.
 */
final class PinnedHttp extends HTTP
{
    private bool $pinned = false;

    private ?int $firstStatus = null;

    private ?string $firstUrl = null;

    private ?string $lastFailure = null;

    public function set_transport(Transport $transport)
    {
        if (!$this->pinned) {
            $this->pinned = true;
            parent::set_transport($transport);
        }
    }

    public function get($url, $headers = [])
    {
        $response = parent::get($url, $headers);

        $this->firstStatus ??= (int) ($response['code'] ?? 0);
        $this->firstUrl    ??= is_string($response['url'] ?? null) ? $response['url'] : (string) $url;
        $this->lastFailure   = self::failureOf($response);

        return $response;
    }

    public function head($url, $headers = [])
    {
        $response = parent::head($url, $headers);
        $this->lastFailure = self::failureOf($response);

        return $response;
    }

    public function post($url, $body, $headers = [])
    {
        $response = parent::post($url, $body, $headers);
        $this->lastFailure = self::failureOf($response);

        return $response;
    }

    /** Why the most recent request failed, in a few words; null if it succeeded. */
    public function lastFailure(): ?string
    {
        return $this->lastFailure;
    }

    /** @param array<string, mixed> $response */
    private static function failureOf(array $response): ?string
    {
        $error = trim((string) ($response['error_description'] ?? ''));
        if ($error === '') {
            $error = trim((string) ($response['error'] ?? ''));
        }
        if ($error !== '') {
            return $error;
        }
        $code = (int) ($response['code'] ?? 0);

        return $code >= 200 && $code < 300 ? null : ($code === 0 ? 'no response' : "HTTP $code");
    }

    public function firstStatus(): ?int
    {
        return $this->firstStatus;
    }

    /** Where the first GET ended up, after any redirects. */
    public function firstUrl(): ?string
    {
        return $this->firstUrl;
    }
}
