<?php

declare(strict_types=1);

namespace Webmention\Storage;

/**
 * Two kinds of blocking:
 *
 * - blocks: a source domain blocked for a whole account
 * - blocklists: a single source URL blocked for one site
 */
final class BlockRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<string> Blocked domains, oldest first. */
    public function domainsForAccount(int $accountId): array
    {
        $rows = $this->db->all('SELECT domain FROM blocks WHERE account_id = ? ORDER BY id', [$accountId]);

        return array_map(static fn (array $row): string => (string) $row['domain'], $rows);
    }

    public function isDomainBlocked(int $accountId, string $domain): bool
    {
        return $this->db->value(
            'SELECT 1 FROM blocks WHERE account_id = ? AND domain = ? LIMIT 1',
            [$accountId, $domain],
        ) !== null;
    }

    public function blockDomain(int $accountId, string $domain): void
    {
        $this->db->insert('blocks', [
            'account_id' => $accountId,
            'domain'     => $domain,
            'created_at' => Database::now(),
        ]);
    }

    public function unblockDomain(int $accountId, string $domain): void
    {
        $this->db->run('DELETE FROM blocks WHERE account_id = ? AND domain = ?', [$accountId, $domain]);
    }

    public function isSourceBlocked(int $siteId, string $source): bool
    {
        return $this->db->value(
            'SELECT 1 FROM blocklists WHERE site_id = ? AND source = ? LIMIT 1',
            [$siteId, $source],
        ) !== null;
    }

    /** Block a source URL for one site. Blocking it again changes nothing. */
    public function blockSource(int $siteId, string $source): void
    {
        if ($this->isSourceBlocked($siteId, $source)) {
            return;
        }

        $this->db->insert('blocklists', [
            'site_id'    => $siteId,
            'source'     => $source,
            'created_at' => Database::now(),
        ]);
    }

    /** Remove every block of this source on this site (older data has duplicates). */
    public function unblockSource(int $siteId, string $source): void
    {
        $this->db->run('DELETE FROM blocklists WHERE site_id = ? AND source = ?', [$siteId, $source]);
    }

    /**
     * Blocked source URLs across all of an account's sites, newest first,
     * optionally only those containing $filter.
     *
     * @return list<array{id: int, site_id: int, domain: string, source: string, created_at: string|null}>
     */
    public function sourcesForAccount(int $accountId, string $filter, int $limit, int $offset): array
    {
        [$where, $params] = self::sourcesWhere($accountId, $filter);

        $rows = $this->db->all(
            "SELECT blocklists.id, blocklists.site_id, sites.domain, blocklists.source, blocklists.created_at
                FROM blocklists JOIN sites ON sites.id = blocklists.site_id
                WHERE $where ORDER BY blocklists.id DESC LIMIT ? OFFSET ?",
            [...$params, max(0, $limit), max(0, $offset)],
        );

        return array_map(static fn (array $row): array => [
            'id'         => (int) $row['id'],
            'site_id'    => (int) $row['site_id'],
            'domain'     => (string) $row['domain'],
            'source'     => (string) $row['source'],
            'created_at' => $row['created_at'] === null ? null : (string) $row['created_at'],
        ], $rows);
    }

    public function countSourcesForAccount(int $accountId, string $filter = ''): int
    {
        [$where, $params] = self::sourcesWhere($accountId, $filter);

        return (int) $this->db->value(
            "SELECT COUNT(*) FROM blocklists JOIN sites ON sites.id = blocklists.site_id WHERE $where",
            $params,
        );
    }

    /** @return array{string, list<mixed>} */
    private static function sourcesWhere(int $accountId, string $filter): array
    {
        $where  = 'sites.account_id = ?';
        $params = [$accountId];

        if ($filter !== '') {
            // The filter is a plain substring; LIKE's wildcards in it are literal.
            // The SQL text must read ESCAPE '\\' (MySQL unescapes the literal once).
            $where .= ' AND blocklists.source LIKE ? ESCAPE \'\\\\\'';
            $params[] = '%' . addcslashes($filter, '\\%_') . '%';
        }

        return [$where, $params];
    }
}
