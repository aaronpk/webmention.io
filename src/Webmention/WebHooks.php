<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use Webmention\Format\Jf2Format;
use Webmention\Format\Url;
use Webmention\Logging\Log;
use Webmention\Model\Link;
use Webmention\Model\Site;
use Webmention\Model\WebhookDelivery;
use Webmention\Storage\WebhookDeliveryRepository;

/**
 * Notifies a site's callback URL. The payloads are part of the public API and
 * must not change shape.
 *
 * Besides the secret in the body, every delivery carries an HMAC of the body
 * in X-Webmention-Signature ("sha256=<hex>", keyed with the same secret), so
 * a receiver can check authenticity without comparing the secret itself.
 *
 * Every attempt is recorded (issue 231) so the site's settings page can show
 * what was sent and what the endpoint answered. Nothing is retried on its
 * own; the owner can re-send a delivery from that page.
 */
final class WebHooks
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly Log $log,
        private readonly WebhookDeliveryRepository $deliveries,
    ) {
    }

    /** @param string $kind "mention" for a delivery in the normal course; "test" when sent by hand. */
    public function notify(Site $site, Link $link, string $source, string $target, bool $private, string $kind = 'mention'): ?WebhookDelivery
    {
        if (Url::blank($site->callbackUrl)) {
            return null;
        }

        return $this->send($site, [
            'secret'  => $site->callbackSecret,
            'source'  => $source,
            'target'  => $target,
            'private' => $private,
            'post'    => Jf2Format::entry($link),
        ], $kind, $link->id);
    }

    public function deleted(Site $site, string $source, string $target, bool $private, ?int $linkId = null): ?WebhookDelivery
    {
        if (Url::blank($site->callbackUrl)) {
            return null;
        }

        return $this->send($site, [
            'secret'  => $site->callbackSecret,
            'source'  => $source,
            'target'  => $target,
            'private' => $private,
            'deleted' => true,
        ], 'deleted', $linkId);
    }

    /**
     * Send an earlier delivery's payload again, to the site's current URL
     * and with its current secret.
     */
    public function resend(Site $site, WebhookDelivery $delivery): ?WebhookDelivery
    {
        if (Url::blank($site->callbackUrl)) {
            return null;
        }

        $payload = json_decode($delivery->requestBody, true);
        if (!is_array($payload)) {
            return null;
        }
        $payload = ['secret' => $site->callbackSecret] + $payload;

        return $this->send($site, $payload, 'test', $delivery->linkId);
    }

    /** @param array<string, mixed> $payload */
    private function send(Site $site, array $payload, string $kind, ?int $linkId): WebhookDelivery
    {
        $url     = (string) $site->callbackUrl;
        $body    = HttpClient::encodeJson($payload);
        $headers = ['Content-Type: application/json'];

        if (!Url::blank($site->callbackSecret)) {
            $headers[] = 'X-Webmention-Signature: sha256=' . hash_hmac('sha256', $body, (string) $site->callbackSecret);
        }

        $started  = hrtime(true);
        $response = $this->http->http()->post($url, $body, $headers);
        $elapsed  = (int) ((hrtime(true) - $started) / 1_000_000);

        if (HttpClient::succeeded($response)) {
            $this->log->info("Webhook sent to $url");
        } else {
            $this->log->warning("Webhook to $url failed: " . ($response['error'] ?: 'HTTP ' . ($response['code'] ?? '?')));
        }

        return $this->deliveries->record($site->id, $linkId, $kind, $url, $response, $elapsed, $body);
    }
}
