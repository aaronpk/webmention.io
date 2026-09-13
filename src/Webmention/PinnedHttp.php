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
 * for every outcome.
 */
final class PinnedHttp extends HTTP
{
    private bool $pinned = false;

    private ?int $firstStatus = null;

    private ?string $firstUrl = null;

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

        return $response;
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
