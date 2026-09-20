<?php

declare(strict_types=1);

namespace Webmention\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Redis;
use Webmention\Format\Jf2Format;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\Database;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\SiteRepository;
use Webmention\Webmention\AccountOverview;
use Webmention\Webmention\SiteActivity;

/**
 * How the service has grown: webmentions, accounts and sites per month, and
 * what kinds have been arriving lately.
 *
 * The monthly webmention count borrows SiteActivity's trick — ids and
 * created_at grow together, so a month is a range of ids — but without a site
 * to narrow it, it still walks the primary key of two and a half million
 * rows. Hence the hour-long cache; nothing here changes by the minute.
 */
final class ServiceActivity
{
    public const MONTHS = 60;
    public const DAYS   = 30;

    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly Database $db,
        private readonly Redis $redis,
        private readonly SiteActivity $months,
        private readonly LinkRepository $links,
        private readonly AccountRepository $accounts,
        private readonly SiteRepository $sites,
    ) {
    }

    /**
     * @return array{months: int, days: int, webmentions: list<array{month: string, label: string, count: int}>,
     *               signups: list<array{month: string, label: string, accounts: int, sites: int}>,
     *               kinds: list<array{type: string, label: string, count: int}>, total: int}
     */
    public function report(int $months = self::MONTHS): array
    {
        $months = max(1, min(self::MONTHS, $months));
        $key    = "webmention:admin:activity:$months";

        $cached = $this->redis->get($key);
        if (is_string($cached) && is_array($decoded = json_decode($cached, true))) {
            /** @var array{months: int, days: int, webmentions: list<array{month: string, label: string, count: int}>, signups: list<array{month: string, label: string, accounts: int, sites: int}>, kinds: list<array{type: string, label: string, count: int}>, total: int} $decoded */
            return $decoded;
        }

        $webmentions = $this->monthlyCounts($months);

        $perAccount = $this->accounts->createdPerMonth();
        $perSite    = $this->sites->createdPerMonth();
        $signups    = [];
        foreach ($webmentions as $bucket) {
            $signups[] = [
                'month'    => $bucket['month'],
                'label'    => $bucket['label'],
                'accounts' => $perAccount[$bucket['month']] ?? 0,
                'sites'    => $perSite[$bucket['month']] ?? 0,
            ];
        }

        $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . self::DAYS . ' days')->format('Y-m-d H:i:s');
        $folded = self::fold($this->links->countsByTypeSince($since));

        $kinds = [];
        foreach (AccountOverview::KINDS as $type => $label) {
            $kinds[] = ['type' => $type, 'label' => $label, 'count' => $folded[$type] ?? 0];
        }

        $report = [
            'months'      => $months,
            'days'        => self::DAYS,
            'webmentions' => $webmentions,
            'signups'     => $signups,
            'kinds'       => $kinds,
            'total'       => array_sum($folded),
        ];

        $this->redis->setex($key, self::CACHE_TTL, json_encode($report) ?: '{}');

        return $report;
    }

    /**
     * Webmentions received per month, service-wide, oldest first.
     *
     * @return list<array{month: string, label: string, count: int}>
     */
    private function monthlyCounts(int $months): array
    {
        $starts = $this->months->monthStarts($months);
        $keys   = array_keys($starts);
        $counts = array_fill_keys($keys, 0);

        $known = [];
        foreach ($keys as $n => $month) {
            if ($starts[$month] !== null) {
                $known[$n] = $starts[$month];
            }
        }

        if ($known !== []) {
            // Each bucket runs from its own first id up to the next one; the
            // last takes everything after it. These ids came from our own
            // query, never from input.
            $indexes = array_keys($known);
            $case    = 'CASE';
            foreach ($indexes as $i => $n) {
                if (isset($indexes[$i + 1])) {
                    $case .= ' WHEN id < ' . $known[$indexes[$i + 1]] . " THEN $n";
                }
            }
            $case .= ' ELSE ' . end($indexes) . ' END';

            foreach ($this->db->all("SELECT $case AS bucket, COUNT(*) AS n FROM links WHERE id >= ? GROUP BY bucket", [reset($known)]) as $row) {
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

    /**
     * Raw link types to the six kinds the dashboard already uses.
     *
     * @param  array<string, int> $byType
     * @return array<string, int>
     */
    private static function fold(array $byType): array
    {
        $out = [];
        foreach ($byType as $type => $count) {
            $kind = match (Jf2Format::relation($type === '' ? null : $type)) {
                'like-of'     => 'like',
                'repost-of'   => 'repost',
                'in-reply-to' => 'reply',
                'bookmark-of' => 'bookmark',
                'rsvp'        => 'rsvp',
                default       => 'mention',
            };
            $out[$kind] = ($out[$kind] ?? 0) + $count;
        }

        return $out;
    }
}
