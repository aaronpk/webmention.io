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

    /** The site for a domain, preferring a live one, then one that has proved it owns it. */
    /**
     * Another account's live, verified site for this domain, if there is one:
     * the domain's pages advertise that account's endpoint, so it is where
     * webmentions for the domain go.
     */
    public function verifiedOnAnotherAccount(int $accountId, string $domain): ?Site
    {
        return $this->verifiedOnOtherAccounts($accountId, $domain)[0] ?? null;
    }

    /** @return list<Site> Every other account's live, verified site for this domain, oldest first. */
    public function verifiedOnOtherAccounts(int $accountId, string $domain): array
    {
        return array_map(
            Site::fromRow(...),
            $this->db->all(
                'SELECT * FROM sites WHERE domain = ? AND account_id <> ? AND verified_at IS NOT NULL AND archived_at IS NULL ORDER BY id',
                [$domain, $accountId],
            ),
        );
    }

    /**
     * Take a verification away: the domain's pages now name another
     * account's endpoint, so webmentions for it go there (see SiteOwnership).
     */
    public function unverify(int $id, string $error): void
    {
        $now = Database::now();
        $this->db->update('sites', $id, [
            'verified_at'             => null,
            'verification_checked_at' => $now,
            'verification_error'      => mb_strcut($error, 0, 255, 'UTF-8'),
            'updated_at'              => $now,
        ]);
    }

    public function findByDomain(string $domain): ?Site
    {
        $row = $this->db->one(
            'SELECT * FROM sites WHERE domain = ? ORDER BY archived_at IS NOT NULL, verified_at IS NULL, id LIMIT 1',
            [$domain],
        );

        return $row === null ? null : Site::fromRow($row);
    }

    /** Record that the site advertises its account's endpoint. */
    public function markVerified(int $id): void
    {
        $now = Database::now();
        $this->db->update('sites', $id, [
            'verified_at'             => $now,
            'verification_checked_at' => $now,
            'verification_error'      => null,
            'updated_at'              => $now,
        ]);
    }

    /** Record a check that found no proof. A verified site is not downgraded by it. */
    public function markChecked(int $id, string $error): void
    {
        $this->db->update('sites', $id, [
            'verification_checked_at' => Database::now(),
            'verification_error'      => mb_strcut($error, 0, 255, 'UTF-8'),
            'updated_at'              => Database::now(),
        ]);
    }

    /**
     * Unverified sites in the order they should be checked: never checked
     * first, then longest since the last check; sites with recent mentions
     * ahead of dormant ones.
     *
     * @return list<Site>
     */
    public function unverifiedToCheck(int $limit): array
    {
        return array_map(Site::fromRow(...), $this->db->all(
            'SELECT s.* FROM sites s
                LEFT JOIN (SELECT site_id, MAX(created_at) AS last_mention FROM links GROUP BY site_id) l ON l.site_id = s.id
                WHERE s.verified_at IS NULL AND s.domain IS NOT NULL AND s.archived_at IS NULL
                ORDER BY s.verification_checked_at IS NULL DESC, s.verification_checked_at ASC, l.last_mention DESC, s.id DESC
                LIMIT ?',
            [max(0, $limit)],
        ));
    }

    /**
     * Verified sites not checked for $days days.
     *
     * @return list<Site>
     */
    public function verifiedToRecheck(int $days, int $limit): array
    {
        return array_map(Site::fromRow(...), $this->db->all(
            'SELECT * FROM sites WHERE verified_at IS NOT NULL AND domain IS NOT NULL AND archived_at IS NULL
                AND (verification_checked_at IS NULL OR verification_checked_at <= ?)
                ORDER BY verification_checked_at IS NULL DESC, verification_checked_at ASC, id LIMIT ?',
            [gmdate('Y-m-d H:i:s', time() - $days * 86400), max(0, $limit)],
        ));
    }

    /**
     * The site's most recently mentioned page URLs, which often carry the
     * endpoint tag when the home page does not.
     *
     * @return list<string>
     */
    public function recentPageHrefs(int $siteId, int $limit = 3): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['href'],
            $this->db->all('SELECT href FROM pages WHERE site_id = ? AND href IS NOT NULL ORDER BY id DESC LIMIT ?', [$siteId, $limit]),
        );
    }

    /**
     * Archive some of an account's sites. Ids that are not the account's, or
     * already archived, are left alone.
     *
     * @param  list<int> $ids
     * @return int How many were archived.
     */
    public function archive(int $accountId, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        $now = Database::now();

        return $this->db->run(
            'UPDATE sites SET archived_at = ?, updated_at = ? WHERE account_id = ? AND archived_at IS NULL AND id IN (' . Database::placeholders($ids) . ')',
            [$now, $now, $accountId, ...$ids],
        )->rowCount();
    }

    public function unarchive(int $accountId, int $id): bool
    {
        return $this->db->run(
            'UPDATE sites SET archived_at = NULL, updated_at = ? WHERE id = ? AND account_id = ? AND archived_at IS NOT NULL',
            [Database::now(), $id, $accountId],
        )->rowCount() > 0;
    }

    /** When the site last received a webmention. The site index answers MAX(id) without reading rows. */
    public function lastMentionAt(int $siteId): ?string
    {
        $value = $this->db->value('SELECT created_at FROM links WHERE id = (SELECT MAX(id) FROM links WHERE site_id = ?)', [$siteId]);

        return $value === null ? null : (string) $value;
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

    public function updateWebhook(int $id, ?string $callbackUrl, ?string $callbackSecret, bool $archiveAvatars, ?string $moderation = null): void
    {
        $this->db->update('sites', $id, [
            'callback_url'    => $callbackUrl,
            'callback_secret' => $callbackSecret,
            'archive_avatars' => $archiveAvatars,
            'moderation'      => $moderation === 'off' ? null : $moderation,
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

    /** How many sites unverifiedToCheck() has waiting, for the admin overview. */
    public function countUnverifiedToCheck(): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM sites WHERE verified_at IS NULL AND domain IS NOT NULL AND archived_at IS NULL',
        );
    }

    /** How many sites verifiedToRecheck() would return, for the admin overview. */
    public function countVerifiedToRecheck(int $days): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM sites WHERE verified_at IS NOT NULL AND domain IS NOT NULL AND archived_at IS NULL
                AND (verification_checked_at IS NULL OR verification_checked_at <= ?)',
            [gmdate('Y-m-d H:i:s', time() - $days * 86400)],
        );
    }

    /** Sites that failed their last verification check, newest failure first. @return list<Site> */
    public function withVerificationError(int $limit): array
    {
        return array_map(Site::fromRow(...), $this->db->all(
            'SELECT * FROM sites WHERE verification_error IS NOT NULL AND verification_error <> \'\' AND archived_at IS NULL
                ORDER BY verification_checked_at DESC, id DESC LIMIT ?',
            [max(1, $limit)],
        ));
    }

    /** Every site on a domain, whoever holds it. @return list<Site> */
    public function allForDomain(string $domain): array
    {
        return array_map(Site::fromRow(...), $this->db->all(
            'SELECT * FROM sites WHERE domain = ? ORDER BY verified_at IS NULL, id',
            [strtolower($domain)],
        ));
    }

    public function countCreatedSince(string $since): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM sites WHERE created_at >= ?', [$since]);
    }

    /** @return array{total: int, verified: int, archived: int} */
    public function totals(): array
    {
        $row = $this->db->one(
            'SELECT COUNT(*) AS total, SUM(verified_at IS NOT NULL) AS verified, SUM(archived_at IS NOT NULL) AS archived FROM sites',
        ) ?? [];

        return [
            'total'    => (int) ($row['total'] ?? 0),
            'verified' => (int) ($row['verified'] ?? 0),
            'archived' => (int) ($row['archived'] ?? 0),
        ];
    }

    /**
     * Sites created per calendar month, for the admin activity page.
     *
     * @return array<string, int> 'YYYY-MM' => count
     */
    public function createdPerMonth(): array
    {
        $out = [];
        foreach ($this->db->all("SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS n FROM sites WHERE created_at IS NOT NULL GROUP BY month") as $row) {
            $out[(string) $row['month']] = (int) $row['n'];
        }

        return $out;
    }
}
