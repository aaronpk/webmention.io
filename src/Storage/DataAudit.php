<?php

declare(strict_types=1);

namespace Webmention\Storage;

/**
 * Consistency checks over the whole database.
 *
 * Fourteen years of the Ruby app, and this year's migrations, left rows that
 * point at things which no longer exist, and URLs with more than one page
 * row. Nothing reported them before. Each check is a count plus a few
 * examples; DataRepair fixes the ones marked repairable, and the rest are
 * reported because they need judgement or are harmless.
 */
final class DataAudit
{
    /** @var list<array{key: string, label: string, count: string, sample: string, format: string}> */
    private const CHECKS = [
        [
            'key'    => 'orphan_links',
            'label'  => 'Webmentions whose site no longer exists',
            'count'  => 'SELECT COUNT(*) FROM links l LEFT JOIN sites bad ON bad.id = l.site_id
                JOIN pages p ON p.id = l.page_id JOIN sites s ON s.id = p.site_id JOIN accounts a ON a.id = s.account_id
                WHERE bad.id IS NULL',
            'sample' => 'SELECT l.id, l.site_id, l.domain FROM links l LEFT JOIN sites bad ON bad.id = l.site_id
                JOIN pages p ON p.id = l.page_id JOIN sites s ON s.id = p.site_id JOIN accounts a ON a.id = s.account_id
                WHERE bad.id IS NULL LIMIT ?',
            'format' => 'webmention %d (site_id %s, from %s)',
        ],
        [
            // Nothing can say where these belong, so the repair leaves them.
            'key'    => 'orphan_links_unplaceable',
            'label'  => 'Webmentions with a missing site and nowhere to place them',
            'count'  => 'SELECT COUNT(*) FROM links l LEFT JOIN sites bad ON bad.id = l.site_id
                LEFT JOIN pages p ON p.id = l.page_id LEFT JOIN sites s ON s.id = p.site_id
                LEFT JOIN accounts a ON a.id = s.account_id
                WHERE bad.id IS NULL AND (p.id IS NULL OR s.id IS NULL OR a.id IS NULL)',
            'sample' => 'SELECT l.id, l.page_id, l.domain FROM links l LEFT JOIN sites bad ON bad.id = l.site_id
                LEFT JOIN pages p ON p.id = l.page_id LEFT JOIN sites s ON s.id = p.site_id
                LEFT JOIN accounts a ON a.id = s.account_id
                WHERE bad.id IS NULL AND (p.id IS NULL OR s.id IS NULL OR a.id IS NULL) LIMIT ?',
            'format' => 'webmention %d (page_id %s, from %s)',
        ],
        [
            'key'    => 'orphan_links_no_page',
            'label'  => 'Webmentions whose page no longer exists',
            'count'  => 'SELECT COUNT(*) FROM links l LEFT JOIN pages p ON p.id = l.page_id WHERE p.id IS NULL',
            'sample' => 'SELECT l.id, l.page_id, l.domain FROM links l LEFT JOIN pages p ON p.id = l.page_id WHERE p.id IS NULL LIMIT ?',
            'format' => 'webmention %d (page_id %s, from %s)',
        ],
        [
            'key'    => 'link_site_mismatch',
            'label'  => 'Webmentions filed under a different site than their page',
            'count'  => 'SELECT COUNT(*) FROM links l JOIN pages p ON p.id = l.page_id WHERE l.site_id <> p.site_id',
            'sample' => 'SELECT l.id, l.site_id, p.site_id AS page_site FROM links l JOIN pages p ON p.id = l.page_id WHERE l.site_id <> p.site_id LIMIT ?',
            'format' => 'webmention %d (site %s, page on site %s)',
        ],
        [
            'key'    => 'link_account_mismatch',
            'label'  => 'Webmentions whose account differs from their site',
            'count'  => 'SELECT COUNT(*) FROM links l JOIN sites s ON s.id = l.site_id WHERE l.account_id <> s.account_id',
            'sample' => 'SELECT l.id, l.account_id, s.account_id AS site_account FROM links l JOIN sites s ON s.id = l.site_id WHERE l.account_id <> s.account_id LIMIT ?',
            'format' => 'webmention %d (account %s, site belongs to %s)',
        ],
        [
            'key'    => 'duplicate_pages',
            'label'  => 'URLs with more than one page row on the same site',
            'count'  => 'SELECT COUNT(*) FROM (SELECT site_id, href FROM pages WHERE href IS NOT NULL GROUP BY site_id, href HAVING COUNT(*) > 1) x',
            'sample' => 'SELECT site_id, href, COUNT(*) n FROM pages WHERE href IS NOT NULL GROUP BY site_id, href HAVING COUNT(*) > 1 LIMIT ?',
            'format' => 'site %s has %3$d rows for %s',
        ],
        [
            'key'    => 'page_missing_site',
            'label'  => 'Pages whose site no longer exists',
            'count'  => 'SELECT COUNT(*) FROM pages p LEFT JOIN sites s ON s.id = p.site_id
                LEFT JOIN sites target ON target.account_id = p.account_id
                    AND target.domain = LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(p.href, "/", 3), "/", -1))
                WHERE s.id IS NULL AND (target.id IS NOT NULL OR NOT EXISTS (SELECT 1 FROM links l WHERE l.page_id = p.id))',
            'sample' => 'SELECT p.id, p.site_id, p.href FROM pages p LEFT JOIN sites s ON s.id = p.site_id
                LEFT JOIN sites target ON target.account_id = p.account_id
                    AND target.domain = LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(p.href, "/", 3), "/", -1))
                WHERE s.id IS NULL AND (target.id IS NOT NULL OR NOT EXISTS (SELECT 1 FROM links l WHERE l.page_id = p.id)) LIMIT ?',
            'format' => 'page %d (site_id %s) %s',
        ],
        [
            // These hold webmentions and their host is on no site of the
            // account, so only their owner can say where they should go.
            'key'    => 'page_missing_site_stuck',
            'label'  => 'Pages whose site is gone that still hold webmentions',
            'count'  => 'SELECT COUNT(*) FROM pages p LEFT JOIN sites s ON s.id = p.site_id
                LEFT JOIN sites target ON target.account_id = p.account_id
                    AND target.domain = LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(p.href, "/", 3), "/", -1))
                WHERE s.id IS NULL AND target.id IS NULL AND EXISTS (SELECT 1 FROM links l WHERE l.page_id = p.id)',
            'sample' => 'SELECT p.id, p.href, (SELECT COUNT(*) FROM links l WHERE l.page_id = p.id) n FROM pages p
                LEFT JOIN sites s ON s.id = p.site_id
                LEFT JOIN sites target ON target.account_id = p.account_id
                    AND target.domain = LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(p.href, "/", 3), "/", -1))
                WHERE s.id IS NULL AND target.id IS NULL AND EXISTS (SELECT 1 FROM links l WHERE l.page_id = p.id) LIMIT ?',
            'format' => 'page %d %s holds %3$d webmentions',
        ],
        [
            'key'    => 'page_account_mismatch',
            'label'  => 'Pages whose account differs from their site',
            'count'  => 'SELECT COUNT(*) FROM pages p JOIN sites s ON s.id = p.site_id WHERE p.account_id <> s.account_id',
            'sample' => 'SELECT p.id, p.account_id, s.account_id AS site_account FROM pages p JOIN sites s ON s.id = p.site_id WHERE p.account_id <> s.account_id LIMIT ?',
            'format' => 'page %d (account %s, site belongs to %s)',
        ],
        [
            'key'    => 'site_missing_account',
            'label'  => 'Sites whose account no longer exists and hold nothing',
            'count'  => 'SELECT COUNT(*) FROM sites s LEFT JOIN accounts a ON a.id = s.account_id WHERE a.id IS NULL
                AND NOT EXISTS (SELECT 1 FROM pages p WHERE p.site_id = s.id) AND NOT EXISTS (SELECT 1 FROM links l WHERE l.site_id = s.id)',
            'sample' => 'SELECT s.id, s.account_id, s.domain FROM sites s LEFT JOIN accounts a ON a.id = s.account_id WHERE a.id IS NULL
                AND NOT EXISTS (SELECT 1 FROM pages p WHERE p.site_id = s.id) AND NOT EXISTS (SELECT 1 FROM links l WHERE l.site_id = s.id) LIMIT ?',
            'format' => 'site %d (account_id %s) %s',
        ],
        [
            // Someone deleted the account but its pages and webmentions are
            // still here; deleting them is the owner's call, not a repair.
            'key'    => 'site_missing_account_holding',
            'label'  => 'Sites whose account is gone that still hold data',
            'count'  => 'SELECT COUNT(*) FROM sites s LEFT JOIN accounts a ON a.id = s.account_id WHERE a.id IS NULL
                AND (EXISTS (SELECT 1 FROM pages p WHERE p.site_id = s.id) OR EXISTS (SELECT 1 FROM links l WHERE l.site_id = s.id))',
            'sample' => 'SELECT s.id, s.domain, (SELECT COUNT(*) FROM links l WHERE l.site_id = s.id) n FROM sites s
                LEFT JOIN accounts a ON a.id = s.account_id WHERE a.id IS NULL
                AND (EXISTS (SELECT 1 FROM pages p WHERE p.site_id = s.id) OR EXISTS (SELECT 1 FROM links l WHERE l.site_id = s.id)) LIMIT ?',
            'format' => 'site %d (%s) holds %3$d webmentions',
        ],
        [
            // Only the owner can say which account should keep the domain;
            // "Bring in a site from another account" on the Sites page is the fix.
            'key'    => 'domain_verified_on_several_accounts',
            'label'  => 'Domains verified on more than one account',
            'count'  => 'SELECT COUNT(*) FROM (SELECT domain FROM sites WHERE verified_at IS NOT NULL AND archived_at IS NULL
                GROUP BY domain HAVING COUNT(DISTINCT account_id) > 1) d',
            'sample' => "SELECT domain, GROUP_CONCAT(CONCAT('#', id, ' (account ', account_id, ')') ORDER BY id SEPARATOR ', ') rows_
                FROM sites WHERE verified_at IS NOT NULL AND archived_at IS NULL
                GROUP BY domain HAVING COUNT(DISTINCT account_id) > 1 LIMIT ?",
            'format' => '%s: sites %s',
        ],
        [
            'key'    => 'alias_missing_page',
            'label'  => 'Page aliases whose page no longer exists',
            'count'  => 'SELECT COUNT(*) FROM page_aliases x LEFT JOIN pages p ON p.id = x.page_id WHERE p.id IS NULL',
            'sample' => 'SELECT x.id, x.page_id, x.href FROM page_aliases x LEFT JOIN pages p ON p.id = x.page_id WHERE p.id IS NULL LIMIT ?',
            'format' => 'alias %d (page_id %s) %s',
        ],
        [
            'key'    => 'blocklist_missing_site',
            'label'  => 'Blocked sources whose site no longer exists',
            'count'  => 'SELECT COUNT(*) FROM blocklists b LEFT JOIN sites s ON s.id = b.site_id WHERE s.id IS NULL',
            'sample' => 'SELECT b.id, b.site_id, b.source FROM blocklists b LEFT JOIN sites s ON s.id = b.site_id WHERE s.id IS NULL LIMIT ?',
            'format' => 'blocked source %d (site_id %s) %s',
        ],
        [
            'key'    => 'duplicate_mentions',
            'label'  => 'Pages holding the same source twice',
            'count'  => 'SELECT COUNT(*) FROM (SELECT page_id, href FROM links GROUP BY page_id, href HAVING COUNT(*) > 1) x',
            'sample' => 'SELECT page_id, href, COUNT(*) n FROM links GROUP BY page_id, href HAVING COUNT(*) > 1 LIMIT ?',
            'format' => 'page %s holds %3$d rows for %s',
        ],
        [
            'key'    => 'verified_and_deleted',
            'label'  => 'Webmentions marked both verified and deleted',
            'count'  => 'SELECT COUNT(*) FROM links WHERE verified = 1 AND deleted = 1',
            'sample' => 'SELECT id, domain, created_at FROM links WHERE verified = 1 AND deleted = 1 LIMIT ?',
            'format' => 'webmention %d from %s, received %s',
        ],
        [
            'key'    => 'page_host_mismatch',
            'label'  => 'Pages whose host is not their site domain',
            'count'  => 'SELECT COUNT(*) FROM pages p JOIN sites s ON s.id = p.site_id WHERE p.href IS NOT NULL AND s.domain IS NOT NULL
                AND LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(p.href, "/", 3), "/", -1)) <> LOWER(s.domain)',
            'sample' => 'SELECT p.id, s.domain, p.href FROM pages p JOIN sites s ON s.id = p.site_id WHERE p.href IS NOT NULL AND s.domain IS NOT NULL
                AND LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(p.href, "/", 3), "/", -1)) <> LOWER(s.domain) LIMIT ?',
            'format' => 'page %d on site %s: %s',
        ],
    ];

    /** Checks DataRepair can put right; everything else is reported only. */
    public const REPAIRABLE = [
        'orphan_links',
        'duplicate_pages',
        'page_missing_site',
        'page_account_mismatch',
        'site_missing_account',
        'alias_missing_page',
        'blocklist_missing_site',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Every check, in report order.
     *
     * @return list<array{key: string, label: string, count: int, repairable: bool, samples: list<string>, seconds: float}>
     */
    public function run(int $samples = 3): array
    {
        $results = [];
        foreach (self::CHECKS as $check) {
            $started = microtime(true);
            $count   = (int) $this->db->value($check['count']);
            $rows    = $count > 0 && $samples > 0 ? $this->db->all($check['sample'], [$samples]) : [];

            $results[] = [
                'key'        => $check['key'],
                'label'      => $check['label'],
                'count'      => $count,
                'repairable' => in_array($check['key'], self::REPAIRABLE, true),
                'samples'    => array_map(
                    static fn (array $row): string => vsprintf($check['format'], array_map(static fn ($v): string => (string) $v, array_values($row))),
                    $rows,
                ),
                'seconds'    => microtime(true) - $started,
            ];
        }

        return $results;
    }

    /** How many rows one check finds. */
    public function count(string $key): int
    {
        foreach (self::CHECKS as $check) {
            if ($check['key'] === $key) {
                return (int) $this->db->value($check['count']);
            }
        }

        throw new \InvalidArgumentException("Unknown check \"$key\".");
    }
}
