<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use Redis;

/**
 * Web hook deliveries waiting to be tried again: a Redis sorted set scored
 * by the time each is due. Members carry everything needed to send, so a
 * retry does not depend on the delivery row still existing (only the newest
 * 50 per site are kept).
 *
 * @phpstan-type Pending array{site_id: int, delivery_id: int, link_id: int|null, kind: string, url: string, attempt: int, body: string}
 */
final class WebhookRetries
{
    public const KEY = 'webmention:webhook-retries';

    public function __construct(private readonly Redis $redis)
    {
    }

    /** @param Pending $pending */
    public function schedule(array $pending, int $due): void
    {
        $this->redis->zAdd(self::KEY, $due, json_encode($pending) ?: '{}');
    }

    /**
     * Take up to $max entries that are due, removing each; an entry another
     * worker removed first is skipped, so nothing is sent twice.
     *
     * @return list<Pending>
     */
    public function due(int $now, int $max): array
    {
        $taken = [];
        foreach ($this->redis->zRangeByScore(self::KEY, '-inf', (string) $now, ['limit' => [0, max(1, $max)]]) as $member) {
            if ((int) $this->redis->zRem(self::KEY, $member) === 0) {
                continue;
            }
            $decoded = json_decode((string) $member, true);
            if (is_array($decoded) && isset($decoded['site_id'], $decoded['kind'], $decoded['url'], $decoded['body'])) {
                /** @var Pending $decoded */
                $taken[] = $decoded;
            }
        }

        return $taken;
    }

    /**
     * When each of a site's pending retries is due, by the delivery id that
     * failed. The set is small: only deliveries that failed recently.
     *
     * @return array<int, int> delivery_id => due (unix time)
     */
    public function forSite(int $siteId): array
    {
        $out = [];
        foreach ($this->all() as [$pending, $due]) {
            if ($pending['site_id'] === $siteId) {
                $out[(int) $pending['delivery_id']] = $due;
            }
        }

        return $out;
    }

    /**
     * Every pending retry, grouped by site: one pass of the set for a page
     * that would otherwise ask about each of an account's sites in turn.
     *
     * @return array<int, array<int, int>> site_id => [delivery_id => due]
     */
    public function bySite(): array
    {
        $out = [];
        foreach ($this->all() as [$pending, $due]) {
            $out[(int) $pending['site_id']][(int) $pending['delivery_id']] = $due;
        }

        return $out;
    }

    /** Drop a site's retries: it was deleted, or its callback URL changed. */
    public function forgetSite(int $siteId): int
    {
        $removed = 0;
        foreach ($this->all() as [$pending, $due, $member]) {
            if ($pending['site_id'] === $siteId) {
                $removed += (int) $this->redis->zRem(self::KEY, $member);
            }
        }

        return $removed;
    }

    public function count(): int
    {
        return (int) $this->redis->zCard(self::KEY);
    }

    /** @return list<array{0: Pending, 1: int, 2: string}> */
    private function all(): array
    {
        $out = [];
        foreach ($this->redis->zRangeByScore(self::KEY, '-inf', '+inf', ['withscores' => true]) as $member => $score) {
            $decoded = json_decode((string) $member, true);
            if (is_array($decoded) && isset($decoded['site_id'])) {
                $decoded['site_id'] = (int) $decoded['site_id'];
                /** @var Pending $decoded */
                $out[] = [$decoded, (int) $score, (string) $member];
            }
        }

        return $out;
    }
}
