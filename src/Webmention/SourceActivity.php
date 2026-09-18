<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use DateTimeImmutable;
use DateTimeZone;
use Redis;
use Webmention\Storage\LinkRepository;

/**
 * Which source domains sent an account webmentions lately, for spotting a
 * flood at a glance. One grouped query over the last DAYS days of the
 * account's rows (a range on account_index_sort), cached for a few minutes
 * and forgotten when the owner blocks or mutes something.
 */
final class SourceActivity
{
    public const DAYS  = 30;
    public const LIMIT = 100;

    private const CACHE_TTL = 300;

    public function __construct(
        private readonly LinkRepository $links,
        private readonly Redis $redis,
    ) {
    }

    /**
     * Busiest first.
     *
     * @return list<array{domain: string, total: int, pending: int, deleted: int, last_seen: string}>
     */
    public function recent(int $accountId): array
    {
        $key    = self::key($accountId);
        $cached = $this->redis->get($key);
        if (is_string($cached) && is_array($decoded = json_decode($cached, true))) {
            /** @var list<array{domain: string, total: int, pending: int, deleted: int, last_seen: string}> $decoded */
            return $decoded;
        }

        $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . self::DAYS . ' days')->format('Y-m-d H:i:s');
        $rows  = $this->links->sourceDomainsSince($accountId, $since, self::LIMIT);
        $this->redis->setex($key, self::CACHE_TTL, json_encode($rows) ?: '[]');

        return $rows;
    }

    /** After a block or a mute, so the page reflects it at once. */
    public function forget(int $accountId): void
    {
        $this->redis->del(self::key($accountId));
    }

    private static function key(int $accountId): string
    {
        return "webmention:sources:$accountId";
    }
}
