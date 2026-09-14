<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use DateTimeImmutable;
use DateTimeZone;
use Redis;
use Webmention\Storage\Database;

/**
 * Webmentions received per month for one site, cheaply.
 *
 * links.id and links.created_at grow together, so a month is an id range,
 * and the site index (site_id, with the primary key implicit) answers
 * "how many of this site's rows fall in each range" without touching a
 * single row. Grouping by created_at instead would scan the whole table.
 *
 * The id that starts each month is the same for every site, so those
 * lookups are cached for a day.
 */
final class SiteActivity
{
    public const MONTHS = 60;

    private const CACHE_TTL = 26 * 3600;

    public function __construct(
        private readonly Database $db,
        private readonly Redis $redis,
    ) {
    }

    /**
     * The first link id in each of the last $months months and the current
     * one, oldest first; null for a month with no row on or after its start.
     *
     * @return array<string, int|null> 'YYYY-MM' => id
     */
    public function monthStarts(int $months = self::MONTHS): array
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
        $key   = "webmention:month-ids:$today:$months";

        $cached = $this->redis->get($key);
        if (is_string($cached) && is_array($decoded = json_decode($cached, true))) {
            /** @var array<string, int|null> $decoded */
            return $decoded;
        }

        $starts = [];
        foreach ($this->months($months) as $month) {
            $id = $this->db->value(
                'SELECT id FROM links WHERE created_at >= ? ORDER BY created_at, id LIMIT 1',
                [$month->format('Y-m-d 00:00:00')],
            );
            $starts[$month->format('Y-m')] = $id === null ? null : (int) $id;
        }

        // Once the current month has a first id, every later row falls in it
        // by being larger, so the map stays right all day. Until it has one
        // (a quiet install), caching would hide the first mentions of the
        // month, so recompute each time; it costs a few milliseconds.
        if (end($starts) !== null) {
            $this->redis->setex($key, self::CACHE_TTL, json_encode($starts) ?: '{}');
        }

        return $starts;
    }

    /**
     * How many webmentions the site received in each month, oldest first,
     * zero-filled. Deleted ones are counted: they were received.
     *
     * @return list<array{month: string, label: string, count: int}>
     */
    public function monthlyCounts(int $siteId, int $months = self::MONTHS): array
    {
        $starts = $this->monthStarts($months);
        $keys   = array_keys($starts);
        $counts = array_fill_keys($keys, 0);

        // Only months that have a first id take part: a month without one
        // (nothing in the whole table on or after its start) has no rows to
        // count, and the month before it runs on to the next known start.
        $known = [];
        foreach ($keys as $n => $month) {
            if ($starts[$month] !== null) {
                $known[$n] = $starts[$month];
            }
        }

        if ($known !== []) {
            // Bucket n runs from its start up to the next known start; the
            // last known month takes everything after it. The ids are
            // integers from our own query, never input.
            $indexes = array_keys($known);
            $case    = 'CASE';
            foreach ($indexes as $i => $n) {
                if (isset($indexes[$i + 1])) {
                    $case .= ' WHEN id < ' . $known[$indexes[$i + 1]] . " THEN $n";
                }
            }
            $case .= ' ELSE ' . end($indexes) . ' END';

            $rows = $this->db->all("SELECT $case AS bucket, COUNT(*) AS n FROM links WHERE site_id = ? AND id >= ? GROUP BY bucket", [$siteId, reset($known)]);
            foreach ($rows as $row) {
                $month = $keys[(int) $row['bucket']] ?? null;
                if ($month !== null) {
                    $counts[$month] = (int) $row['n'];
                }
            }
        }

        $out = [];
        foreach ($counts as $month => $count) {
            $out[] = [
                'month' => $month,
                'label' => (new DateTimeImmutable($month . '-01'))->format('M Y'),
                'count' => $count,
            ];
        }

        return $out;
    }

    /** @return list<DateTimeImmutable> The first day of each month in the window, oldest first. */
    private function months(int $months): array
    {
        $current = new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone('UTC'));
        $list    = [];
        for ($i = $months; $i >= 0; $i--) {
            $list[] = $current->modify("-$i months");
        }

        return $list;
    }
}
