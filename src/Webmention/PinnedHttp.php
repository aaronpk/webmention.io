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
 * for every outcome; how the most recent request failed, for callers (the
 * IndieAuth client) that only say a body was unusable; and a trace of every
 * request, so a failed sign-in can show what was fetched and what answered.
 * The trace never holds request bodies or response bodies, and the `code`
 * and `state` query parameters are masked in its URLs.
 */
final class PinnedHttp extends HTTP
{
    private bool $pinned = false;

    private ?int $firstStatus = null;

    private ?string $firstUrl = null;

    private ?string $lastFailure = null;

    /** @var list<array{method: string, url: string, final_url: string, status: int, error: ?string, type: ?string, bytes: int, ms: int}> */
    private array $requests = [];

    public function set_transport(Transport $transport)
    {
        if (!$this->pinned) {
            $this->pinned = true;
            parent::set_transport($transport);
        }
    }

    public function get($url, $headers = [])
    {
        $started  = hrtime(true);
        $response = parent::get($url, $headers);

        $this->firstStatus ??= (int) ($response['code'] ?? 0);
        $this->firstUrl    ??= is_string($response['url'] ?? null) ? $response['url'] : (string) $url;
        $this->remember('GET', (string) $url, $response, $started);

        return $response;
    }

    public function head($url, $headers = [])
    {
        $started  = hrtime(true);
        $response = parent::head($url, $headers);
        $this->remember('HEAD', (string) $url, $response, $started);

        return $response;
    }

    public function post($url, $body, $headers = [])
    {
        $started  = hrtime(true);
        $response = parent::post($url, $body, $headers);
        $this->remember('POST', (string) $url, $response, $started);

        return $response;
    }

    /** @return list<array{method: string, url: string, final_url: string, status: int, error: ?string, type: ?string, bytes: int, ms: int}> */
    public function requests(): array
    {
        return $this->requests;
    }

    /** The most recent request to a URL, by its masked form. */
    public function requestFor(string $url): ?array
    {
        $url = self::mask($url);
        foreach (array_reverse($this->requests) as $request) {
            if ($request['url'] === $url) {
                return $request;
            }
        }

        return null;
    }

    /** A URL with its `code` and `state` values hidden, safe for a page or a log. */
    public static function mask(string $url): string
    {
        return (string) preg_replace('/([?&](?:code|state)=)[^&#]*/i', '$1…', $url);
    }

    /** @param array<string, mixed> $response */
    private function remember(string $method, string $url, array $response, int $started): void
    {
        $this->lastFailure = self::failureOf($response);
        $type = preg_match('/^content-type:\s*([^\r\n]+)/im', (string) ($response['header'] ?? ''), $m) === 1 ? trim($m[1]) : null;

        $this->requests[] = [
            'method'    => $method,
            'url'       => self::mask($url),
            'final_url' => self::mask(is_string($response['url'] ?? null) && $response['url'] !== '' ? $response['url'] : $url),
            'status'    => (int) ($response['code'] ?? 0),
            'error'     => $this->lastFailure,
            'type'      => $type,
            'bytes'     => strlen((string) ($response['body'] ?? '')),
            'ms'        => (int) ((hrtime(true) - $started) / 1_000_000),
        ];
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
