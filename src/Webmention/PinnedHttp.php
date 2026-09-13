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
 * It also remembers the status of the first GET, which is the page XRay was
 * asked to parse. XRay doesn't report the status for every outcome.
 */
final class PinnedHttp extends HTTP
{
    private bool $pinned = false;

    private ?int $firstStatus = null;

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

        return $response;
    }

    public function firstStatus(): ?int
    {
        return $this->firstStatus;
    }
}
