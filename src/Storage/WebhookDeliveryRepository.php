<?php

declare(strict_types=1);

namespace Webmention\Storage;

use Webmention\Model\WebhookDelivery;

final class WebhookDeliveryRepository
{
    /** Deliveries kept per site; older ones are dropped as new ones arrive. */
    public const KEEP = 50;

    /** How much of the endpoint's reply is kept. */
    public const RESPONSE_EXCERPT = 2000;

    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $response p3k\HTTP's response array (code, body, error). */
    public function record(int $siteId, ?int $linkId, string $kind, string $url, array $response, int $durationMs, string $requestBody): WebhookDelivery
    {
        $code  = (int) ($response['code'] ?? 0);
        $body  = $response['body'] ?? null;
        // The transport gives a short code in "error" and, when it has one, a
        // sentence in "error_description"; the sentence is the useful part.
        $error = trim((string) ($response['error_description'] ?? ''));
        if ($error === '') {
            $error = trim((string) ($response['error'] ?? ''));
        }

        $id = $this->db->insert('webhook_deliveries', [
            'site_id'       => $siteId,
            'link_id'       => $linkId,
            'kind'          => $kind,
            'url'           => mb_substr($url, 0, 255),
            'status_code'   => $code > 0 ? $code : null,
            'error'         => $error === '' ? null : mb_substr($error, 0, 255),
            'duration_ms'   => max(0, $durationMs),
            'request_body'  => $requestBody,
            'response_body' => $body === null || $body === '' ? null : mb_substr((string) $body, 0, self::RESPONSE_EXCERPT),
            'created_at'    => Database::now(),
        ]);

        $this->prune($siteId);

        return $this->findForSite($siteId, $id) ?? throw new \RuntimeException('Delivery vanished after insert.');
    }

    /** @return list<WebhookDelivery> Newest first. */
    public function recentForSite(int $siteId, int $limit = self::KEEP): array
    {
        return array_map(
            WebhookDelivery::fromRow(...),
            $this->db->all('SELECT * FROM webhook_deliveries WHERE site_id = ? ORDER BY id DESC LIMIT ?', [$siteId, max(1, $limit)]),
        );
    }

    public function findForSite(int $siteId, int $id): ?WebhookDelivery
    {
        $row = $this->db->one('SELECT * FROM webhook_deliveries WHERE id = ? AND site_id = ?', [$id, $siteId]);

        return $row === null ? null : WebhookDelivery::fromRow($row);
    }

    public function latestForSite(int $siteId): ?WebhookDelivery
    {
        return $this->recentForSite($siteId, 1)[0] ?? null;
    }

    /** Keep only the newest KEEP rows for the site. */
    private function prune(int $siteId): void
    {
        $cut = $this->db->value('SELECT id FROM webhook_deliveries WHERE site_id = ? ORDER BY id DESC LIMIT 1 OFFSET ?', [$siteId, self::KEEP]);
        if ($cut !== null) {
            $this->db->run('DELETE FROM webhook_deliveries WHERE site_id = ? AND id <= ?', [$siteId, (int) $cut]);
        }
    }
}
