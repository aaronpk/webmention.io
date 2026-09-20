<?php

declare(strict_types=1);

namespace Webmention\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Redis;
use Webmention\Controllers\WebmentionController;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\Database;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\SiteRepository;
use Webmention\Webmention\Queue;
use Webmention\Webmention\SiteDeleter;
use Webmention\Webmention\WebhookRetries;
use Webmention\Webmention\WorkerHeartbeat;

/**
 * Is the service healthy, and what has been arriving.
 *
 * Everything here was already being measured somewhere; this only reads it.
 * The database side is cached for a minute so refreshing the page is free,
 * while the queue, the retry backlog and the workers are read live: their
 * whole value is being up to date.
 */
final class ServiceOverview
{
    public const RECHECK_DAYS = 30;

    private const CACHE_KEY = 'webmention:admin:overview';
    private const CACHE_TTL = 60;

    public function __construct(
        private readonly Database $db,
        private readonly Redis $redis,
        private readonly Queue $queue,
        private readonly WebhookRetries $retries,
        private readonly WorkerHeartbeat $workers,
        private readonly LinkRepository $links,
        private readonly SiteRepository $sites,
        private readonly AccountRepository $accounts,
    ) {
    }

    /** @return array<string, mixed> */
    public function now(): array
    {
        return [
            'queue'      => $this->queue->length(),
            'queue_max'  => WebmentionController::MAX_QUEUE_LENGTH,
            'retries'    => $this->retries->count(),
            'purges'     => (int) $this->redis->lLen(SiteDeleter::QUEUE),
            'workers'    => $this->workers->all(),
            'stale_after' => WorkerHeartbeat::STALE_AFTER,
            ...$this->counted(),
        ];
    }

    /**
     * The parts that cost a query, cached for a minute.
     *
     * @return array<string, mixed>
     */
    private function counted(): array
    {
        $cached = $this->redis->get(self::CACHE_KEY);
        if (is_string($cached) && is_array($decoded = json_decode($cached, true))) {
            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        $now    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $format = 'Y-m-d H:i:s';
        $today  = $now->setTime(0, 0)->format($format);
        $week   = $now->modify('-7 days')->format($format);
        $month  = $now->modify('-30 days')->format($format);

        $counted = [
            'received' => [
                ['label' => 'Today', 'since' => $today, ...$this->links->countsByStatusSince($today)],
                ['label' => 'Last 7 days', 'since' => $week, ...$this->links->countsByStatusSince($week)],
                ['label' => 'Last 30 days', 'since' => $month, ...$this->links->countsByStatusSince($month)],
            ],
            'accounts' => [
                'total' => $this->accounts->countAll(),
                'week'  => $this->accounts->countCreatedSince($week),
                'month' => $this->accounts->countCreatedSince($month),
            ],
            'sites' => [
                ...$this->sites->totals(),
                'week'  => $this->sites->countCreatedSince($week),
                'month' => $this->sites->countCreatedSince($month),
            ],
            'verification' => [
                'unverified' => $this->sites->countUnverifiedToCheck(),
                'recheck'    => $this->sites->countVerifiedToRecheck(self::RECHECK_DAYS),
                'days'       => self::RECHECK_DAYS,
                'failing'    => $this->failingVerification(),
            ],
            'webhooks'   => $this->webhookFailures($now->modify('-1 day')->format($format)),
            'tables'     => $this->tables(),
            'counted_at' => $now->format('H:i') . ' UTC',
        ];

        $this->redis->setex(self::CACHE_KEY, self::CACHE_TTL, json_encode($counted) ?: '{}');

        return $counted;
    }

    private function failingVerification(): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM sites WHERE verification_error IS NOT NULL AND verification_error <> '' AND archived_at IS NULL",
        );
    }

    /**
     * Web hook deliveries that did not succeed in the last day. The table
     * keeps only the newest fifty rows per site, so this is a small scan.
     *
     * @return array{failed: int, total: int}
     */
    private function webhookFailures(string $since): array
    {
        $row = $this->db->one(
            'SELECT COUNT(*) AS total, SUM(status_code IS NULL OR status_code >= 400) AS failed
                FROM webhook_deliveries WHERE created_at >= ?',
            [$since],
        ) ?? [];

        return ['total' => (int) ($row['total'] ?? 0), 'failed' => (int) ($row['failed'] ?? 0)];
    }

    /**
     * Roughly how big each table is. table_rows is InnoDB's estimate, which is
     * the point: COUNT(*) on links reads two and a half million rows.
     *
     * @return list<array{name: string, rows: int, data_mb: int, index_mb: int}>
     */
    private function tables(): array
    {
        $rows = $this->db->all(
            'SELECT table_name AS name, table_rows AS rows_estimate,
                    ROUND(data_length / 1048576) AS data_mb, ROUND(index_length / 1048576) AS index_mb
                FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_type = "BASE TABLE"
                ORDER BY data_length DESC LIMIT 20',
        );

        return array_map(static fn (array $r): array => [
            'name'     => (string) $r['name'],
            'rows'     => (int) $r['rows_estimate'],
            'data_mb'  => (int) $r['data_mb'],
            'index_mb' => (int) $r['index_mb'],
        ], $rows);
    }
}
