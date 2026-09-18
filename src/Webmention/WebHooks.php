<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use Webmention\Format\Jf2Format;
use Webmention\Format\Url;
use Webmention\Logging\Log;
use Webmention\Model\Link;
use Webmention\Model\Site;
use Webmention\Model\WebhookDelivery;
use Webmention\Storage\SiteRepository;
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
 * what was sent and what the endpoint answered. A mention or deletion the
 * endpoint did not take (no answer, 5xx, 408 or 429) is tried again by the
 * workers with backoff, RETRY_DELAYS after each failure; any other 4xx is
 * final, since the endpoint answered and refused. Delivery is therefore
 * at-least-once: a slow endpoint can be told the same thing twice. The owner
 * can also re-send a delivery by hand from the settings page; those are
 * never retried.
 */
final class WebHooks
{
    /** Seconds after the 1st, 2nd, … failed attempt before the next; the list's length caps the retries. */
    public const RETRY_DELAYS = [60, 300, 1800, 7200, 43200];

    public const MAX_ATTEMPTS = 6;

    /** Kinds that are retried; "test" is sent by hand and is not. */
    private const RETRIED_KINDS = ['mention', 'deleted'];

    public function __construct(
        private readonly HttpClient $http,
        private readonly Log $log,
        private readonly WebhookDeliveryRepository $deliveries,
        private readonly SiteRepository $sites,
        private readonly WebhookRetries $retries,
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

    /**
     * Send the retries that are due. Called by each worker turn; two workers
     * never send the same one (see WebhookRetries::due). A retry is dropped
     * without sending when the site is gone or archived, has no callback URL
     * any more, or points somewhere else now: the new endpoint gets only what
     * arrives from now on.
     *
     * @return int How many were sent.
     */
    public function retryDue(?int $now = null, int $max = 20): int
    {
        $sent = 0;
        foreach ($this->retries->due($now ?? time(), $max) as $pending) {
            $site = $this->sites->find((int) $pending['site_id']);
            if ($site === null || $site->isArchived() || Url::blank($site->callbackUrl)) {
                $this->log->info("Webhook retry for site {$pending['site_id']} dropped: site gone, archived or without a callback URL");
                continue;
            }
            if (mb_substr((string) $site->callbackUrl, 0, 255) !== $pending['url']) {
                $this->log->info("Webhook retry for site {$site->id} dropped: callback URL changed");
                continue;
            }
            $payload = json_decode((string) $pending['body'], true);
            if (!is_array($payload)) {
                continue;
            }
            // The current secret, in case it was rotated in between.
            $payload = ['secret' => $site->callbackSecret] + $payload;

            $this->send($site, $payload, (string) $pending['kind'], $pending['link_id'] === null ? null : (int) $pending['link_id'], (int) $pending['attempt'] + 1);
            $sent++;
        }

        return $sent;
    }

    /** @return array<int, int> For the site's settings page: failed delivery id => when its retry is due. */
    public function pendingRetries(int $siteId): array
    {
        return $this->retries->forSite($siteId);
    }

    /** Whether a failed delivery is one the workers will try again. */
    public static function retryable(WebhookDelivery $delivery): bool
    {
        if ($delivery->succeeded() || !in_array($delivery->kind, self::RETRIED_KINDS, true) || $delivery->attempt >= self::MAX_ATTEMPTS) {
            return false;
        }
        if ($delivery->error !== null || $delivery->statusCode === null) {
            return true; // no answer at all
        }

        return $delivery->statusCode >= 500 || in_array($delivery->statusCode, [408, 429], true);
    }

    /** @param array<string, mixed> $payload */
    private function send(Site $site, array $payload, string $kind, ?int $linkId, int $attempt = 1): WebhookDelivery
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

        $try = $attempt > 1 ? " (attempt $attempt)" : '';
        if (HttpClient::succeeded($response)) {
            $this->log->info("Webhook sent to $url$try");
        } else {
            $this->log->warning("Webhook to $url failed$try: " . ($response['error'] ?: 'HTTP ' . ($response['code'] ?? '?')));
        }

        $delivery = $this->deliveries->record($site->id, $linkId, $kind, $url, $response, $elapsed, $body, $attempt);

        if (self::retryable($delivery)) {
            $delay = self::RETRY_DELAYS[$attempt - 1] ?? end(self::RETRY_DELAYS);
            $this->retries->schedule([
                'site_id'     => $site->id,
                'delivery_id' => $delivery->id,
                'link_id'     => $linkId,
                'kind'        => $kind,
                'url'         => $delivery->url,
                'attempt'     => $attempt,
                'body'        => $body,
            ], time() + $delay);
            $this->log->info("Webhook to $url will be retried in {$delay}s");
        }

        return $delivery;
    }
}
