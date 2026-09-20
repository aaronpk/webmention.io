<?php

declare(strict_types=1);

namespace Webmention\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Redis;
use Webmention\Storage\LinkRepository;

/**
 * Which domains have been sending the whole service webmentions lately.
 *
 * The busiest list answers "who is loud"; ordering by deleted answers "who is
 * a nuisance", since a domain whose mentions people keep deleting, across
 * many accounts, is the shape abuse takes here. Hidden and deleted rows are
 * counted, because they were still received and still cost the fetches.
 */
final class SourceRadar
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
     * @param  'total'|'deleted' $sort
     * @return list<array{domain: string, total: int, pending: int, deleted: int, accounts: int, last_seen: string}>
     */
    public function busiest(string $sort = 'total'): array
    {
        $sort = $sort === 'deleted' ? 'deleted' : 'total';
        $key  = "webmention:admin:sources:$sort";

        $cached = $this->redis->get($key);
        if (is_string($cached) && is_array($decoded = json_decode($cached, true))) {
            /** @var list<array{domain: string, total: int, pending: int, deleted: int, accounts: int, last_seen: string}> $decoded */
            return $decoded;
        }

        $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . self::DAYS . ' days')->format('Y-m-d H:i:s');

        $sources = $this->links->sourceDomainsEverywhereSince($since, self::LIMIT, byDeleted: $sort === 'deleted');

        $this->redis->setex($key, self::CACHE_TTL, json_encode($sources) ?: '[]');

        return $sources;
    }
}
