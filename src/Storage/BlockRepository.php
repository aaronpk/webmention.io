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

    public function blockSource(int $siteId, string $source): void
    {
        $this->db->insert('blocklists', [
            'site_id'    => $siteId,
            'source'     => $source,
            'created_at' => Database::now(),
        ]);
    }
}
