<?php

declare(strict_types=1);

namespace Webmention\Storage;

use PDOException;
use Webmention\Model\Site;

final class SiteRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function find(int $id): ?Site
    {
        $row = $this->db->one('SELECT * FROM sites WHERE id = ?', [$id]);

        return $row === null ? null : Site::fromRow($row);
    }

    /** A site, only if it belongs to this account. */
    public function findForAccount(int $accountId, int $id): ?Site
    {
        $row = $this->db->one('SELECT * FROM sites WHERE id = ? AND account_id = ?', [$id, $accountId]);

        return $row === null ? null : Site::fromRow($row);
    }

    public function findByAccountAndDomain(int $accountId, string $domain): ?Site
    {
        $row = $this->db->one(
            'SELECT * FROM sites WHERE account_id = ? AND domain = ? ORDER BY id LIMIT 1',
            [$accountId, $domain],
        );

        return $row === null ? null : Site::fromRow($row);
    }

    public function findByDomain(string $domain): ?Site
    {
        $row = $this->db->one('SELECT * FROM sites WHERE domain = ? ORDER BY id LIMIT 1', [$domain]);

        return $row === null ? null : Site::fromRow($row);
    }

    /** @return list<Site> */
    public function listForAccount(int $accountId): array
    {
        return array_map(
            Site::fromRow(...),
            $this->db->all('SELECT * FROM sites WHERE account_id = ? ORDER BY id', [$accountId]),
        );
    }

    /**
     * The account's site for a domain, created if it does not exist.
     *
     * Two requests can both find nothing and both insert; the unique index on
     * (account_id, domain) refuses the second, which then reads the first.
     * Use this rather than create() for anything a user can trigger.
     */
    public function findOrCreate(int $accountId, string $domain): Site
    {
        $site = $this->findByAccountAndDomain($accountId, $domain);
        if ($site !== null) {
            return $site;
        }

        try {
            return $this->create($accountId, $domain);
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            return $this->findByAccountAndDomain($accountId, $domain) ?? throw $e;
        }
    }

    public function create(int $accountId, string $domain): Site
    {
        $now = Database::now();
        $id  = $this->db->insert('sites', [
            'account_id' => $accountId,
            'domain'     => $domain,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($id) ?? throw new \RuntimeException('Site vanished after insert.');
    }

    public function updateWebhook(int $id, ?string $callbackUrl, ?string $callbackSecret, bool $archiveAvatars): void
    {
        $this->db->update('sites', $id, [
            'callback_url'    => $callbackUrl,
            'callback_secret' => $callbackSecret,
            'archive_avatars' => $archiveAvatars,
            'updated_at'      => Database::now(),
        ]);
    }

    public function pageCount(int $siteId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM pages WHERE site_id = ?', [$siteId]);
    }

    public function linkCount(int $siteId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM links WHERE site_id = ?', [$siteId]);
    }
}
