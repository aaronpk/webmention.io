<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use Webmention\Format\Jf2Format;
use Webmention\Format\Url;
use Webmention\Logging\Log;
use Webmention\Model\Link;
use Webmention\Model\Site;

/**
 * Notifies a site's callback URL. The payloads are part of the public API and
 * must not change shape.
 */
final class WebHooks
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly Log $log,
    ) {
    }

    public function notify(Site $site, Link $link, string $source, string $target, bool $private): void
    {
        if (Url::blank($site->callbackUrl)) {
            return;
        }

        $this->send($site, [
            'secret'  => $site->callbackSecret,
            'source'  => $source,
            'target'  => $target,
            'private' => $private,
            'post'    => Jf2Format::entry($link),
        ]);
    }

    public function deleted(Site $site, string $source, string $target, bool $private): void
    {
        if (Url::blank($site->callbackUrl)) {
            return;
        }

        $this->send($site, [
            'secret'  => $site->callbackSecret,
            'source'  => $source,
            'target'  => $target,
            'private' => $private,
            'deleted' => true,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function send(Site $site, array $payload): void
    {
        $url      = (string) $site->callbackUrl;
        $response = $this->http->postJson($url, $payload);

        if (HttpClient::succeeded($response)) {
            $this->log->info("Webhook sent to $url");
        } else {
            $this->log->warning("Webhook to $url failed: " . ($response['error'] ?: 'HTTP ' . ($response['code'] ?? '?')));
        }
    }
}
