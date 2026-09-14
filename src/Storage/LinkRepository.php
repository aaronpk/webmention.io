<?php

declare(strict_types=1);

namespace Webmention\Storage;

use Webmention\Format\Jf2Format;
use Webmention\Model\Link;
use Webmention\Model\Mute;

final class LinkRepository
{
    /** Every output format needs the target page's URL and the site's age. */
    private const SELECT = 'SELECT links.*, pages.href AS page_href, sites.created_at AS site_created_at
        FROM links
        LEFT JOIN pages ON pages.id = links.page_id
        LEFT JOIN sites ON sites.id = links.site_id';

    public function __construct(private readonly Database $db)
    {
    }

    public function find(int $id): ?Link
    {
        return $this->first(self::SELECT . ' WHERE links.id = ?', [$id]);
    }

    /** A link, only if it belongs to this account. */
    public function findForAccount(int $accountId, int $id): ?Link
    {
        return $this->first(self::SELECT . ' WHERE links.id = ? AND links.account_id = ?', [$id, $accountId]);
    }

    /** Deleted links included: re-sending a deleted webmention must not create a duplicate. */
    public function findByPageAndHref(int $pageId, string $href): ?Link
    {
        return $this->first(
            self::SELECT . ' WHERE links.page_id = ? AND links.href = ? ORDER BY links.id LIMIT 1',
            [$pageId, $href],
        );
    }

    /** @return list<Link> */
    public function recentForAccount(int $accountId, int $limit): array
    {
        return $this->many(
            self::SELECT . ' WHERE links.account_id = ? AND links.verified = 1 AND links.deleted = 0
                ORDER BY links.created_at DESC LIMIT ?',
            [$accountId, $limit],
        );
    }

    /** @return list<Link> Undeleted links from one source URL, to any page in the account. */
    public function fromSourceForAccount(int $accountId, string $href): array
    {
        return $this->many(
            self::SELECT . ' WHERE links.account_id = ? AND links.href = ? AND links.deleted = 0 ORDER BY links.id',
            [$accountId, $href],
        );
    }

    public function countFromDomainForAccount(int $accountId, string $domain): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM links WHERE account_id = ? AND domain = ?',
            [$accountId, $domain],
        );
    }

    /** @param array<string, mixed> $row */
    public function create(array $row): int
    {
        $now = Database::now();

        return $this->db->insert('links', [...$row, 'created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $row */
    public function update(int $id, array $row): void
    {
        $this->db->update('links', $id, [...$row, 'updated_at' => Database::now()]);
    }

    public function markDeleted(int $id): void
    {
        $this->update($id, ['deleted' => 1]);
    }

    public function markDeletedFromDomainForAccount(int $accountId, string $domain): void
    {
        $this->db->run(
            'UPDATE links SET deleted = 1, updated_at = ? WHERE account_id = ? AND domain = ?',
            [Database::now(), $accountId, $domain],
        );
    }

    // ---- Moderation (see Webmention\Moderation) ----

    /** @return list<Link> Mentions held for review, newest first. */
    public function pendingForAccount(int $accountId, int $limit, int $offset = 0): array
    {
        return $this->many(
            self::SELECT . " WHERE links.account_id = ? AND links.status = 'pending' AND links.deleted = 0
                ORDER BY links.created_at DESC, links.id DESC LIMIT ? OFFSET ?",
            [$accountId, max(0, $limit), max(0, $offset)],
        );
    }

    public function countPendingForAccount(int $accountId): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM links WHERE account_id = ? AND status = 'pending' AND deleted = 0",
            [$accountId],
        );
    }

    /** Make a held or hidden mention public. */
    public function publish(int $id): void
    {
        $this->update($id, ['verified' => 1, 'status' => null]);
    }

    /** @return list<Link> The mentions from one source domain that were waiting, now published. */
    public function publishPendingFromDomain(int $accountId, string $domain): array
    {
        $links = $this->many(
            self::SELECT . " WHERE links.account_id = ? AND links.status = 'pending' AND links.deleted = 0 AND links.domain = ? ORDER BY links.id",
            [$accountId, $domain],
        );
        foreach ($links as $link) {
            $this->publish($link->id);
        }

        return array_values(array_filter(array_map(fn (Link $l): ?Link => $this->find($l->id), $links)));
    }

    /** Whether the account has ever published a mention from this source domain (the "first-time sender" test). */
    public function hasPublishedFromDomain(int $accountId, string $domain): bool
    {
        return $this->db->value(
            'SELECT 1 FROM links WHERE account_id = ? AND domain = ? AND verified = 1 AND deleted = 0 AND status IS NULL LIMIT 1',
            [$accountId, $domain],
        ) !== null;
    }

    /** Hide every published mention a new mute rule covers. Returns how many. */
    public function hideMatching(int $accountId, Mute $rule): int
    {
        [$where, $params] = MuteRepository::predicate($rule);

        return $this->db->run(
            "UPDATE links SET verified = 0, status = 'hidden', updated_at = ?
                WHERE account_id = ? AND verified = 1 AND deleted = 0 AND status IS NULL AND $where",
            [Database::now(), $accountId, ...$params],
        )->rowCount();
    }

    /**
     * After a rule is removed: publish the hidden mentions no remaining rule
     * covers. Returns how many.
     *
     * @param list<Mute> $remaining
     */
    public function restoreHidden(int $accountId, array $remaining): int
    {
        $clauses = [];
        $params  = [Database::now(), $accountId];
        foreach ($remaining as $rule) {
            [$where, $ruleParams] = MuteRepository::predicate($rule);
            $clauses[] = $where;
            array_push($params, ...$ruleParams);
        }
        $still = $clauses === [] ? '' : ' AND NOT (' . implode(' OR ', $clauses) . ')';

        return $this->db->run(
            "UPDATE links SET verified = 1, status = NULL, updated_at = ? WHERE account_id = ? AND status = 'hidden' AND deleted = 0$still",
            $params,
        )->rowCount();
    }

    /**
     * Deleted mentions, most recently deleted first, for clients pruning a
     * cache (issue 128). createdAfter and idAfter apply to the deletion time
     * (updated_at) and id.
     *
     * @return list<Link>
     */
    public function searchDeleted(LinkSearch $search): array
    {
        $where  = ['links.deleted = 1'];
        $params = [];

        if (!$search->includePrivate) {
            $where[] = 'links.is_private = 0';
        }
        if ($search->accountId !== null) {
            $where[]  = 'links.account_id = ?';
            $params[] = $search->accountId;
        }
        if ($search->pageIds !== null) {
            if ($search->pageIds === []) {
                return [];
            }
            $where[] = 'links.page_id IN (' . Database::placeholders($search->pageIds) . ')';
            array_push($params, ...$search->pageIds);
        }
        if ($search->createdAfter !== null) {
            $where[]  = 'links.updated_at > ?';
            $params[] = $search->createdAfter;
        }
        if ($search->idAfter !== null) {
            $where[]  = 'links.id > ?';
            $params[] = $search->idAfter;
        }

        $params[] = max(0, $search->limit);
        $params[] = max(0, $search->offset);

        return $this->many(
            self::SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY links.updated_at DESC, links.id DESC LIMIT ? OFFSET ?',
            $params,
        );
    }

    /**
     * @param list<int> $pageIds
     */
    public function countForPages(array $pageIds, bool $includePrivate = false): int
    {
        if ($pageIds === []) {
            return 0;
        }

        return (int) $this->db->value(
            'SELECT COUNT(*) FROM links WHERE page_id IN (' . Database::placeholders($pageIds) . ')
                AND verified = 1 AND deleted = 0' . ($includePrivate ? '' : ' AND is_private = 0'),
            $pageIds,
        );
    }

    /**
     * @param  list<int>          $pageIds
     * @return array<string, int> type => count; rows with no type are counted under ''
     */
    public function typeCountsForPages(array $pageIds, bool $includePrivate = false): array
    {
        if ($pageIds === []) {
            return [];
        }

        $rows = $this->db->all(
            'SELECT type, COUNT(1) AS num FROM links WHERE page_id IN (' . Database::placeholders($pageIds) . ')
                AND deleted = 0 AND verified = 1' . ($includePrivate ? '' : ' AND is_private = 0') . ' GROUP BY type',
            $pageIds,
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) ($row['type'] ?? '')] = (int) $row['num'];
        }

        return $counts;
    }

    /** @return list<Link> */
    public function search(LinkSearch $search): array
    {
        if ($search->pageIds === []) {
            return [];
        }

        [$where, $params] = self::whereFor($search);

        $dir   = $search->descending ? 'DESC' : 'ASC';
        $order = match ($search->sortBy) {
            'published' => "links.published $dir, links.created_at $dir",
            'updated'   => "links.updated_at $dir",
            'rsvp'      => "FIELD(links.type, 'rsvp-no', 'rsvp-interested', 'rsvp-maybe', 'rsvp-yes') $dir, links.created_at $dir",
            default     => "links.created_at $dir",
        };

        $params[] = max(0, $search->limit);
        $params[] = max(0, $search->offset);

        return $this->many(
            self::SELECT . " WHERE $where ORDER BY $order LIMIT ? OFFSET ?",
            $params,
        );
    }

    /** The site's newest published mention, for a test web hook delivery. */
    public function latestPublishedForSite(int $siteId): ?Link
    {
        return $this->first(self::SELECT . ' WHERE links.site_id = ? AND links.verified = 1 AND links.deleted = 0 ORDER BY links.id DESC LIMIT 1', [$siteId]);
    }

    /** How many links a search matches in all, ignoring its page. */
    public function count(LinkSearch $search): int
    {
        if ($search->pageIds === []) {
            return 0;
        }

        [$where, $params] = self::whereFor($search);

        return (int) $this->db->value("SELECT COUNT(*) FROM links WHERE $where", $params);
    }

    /** @return array{string, list<mixed>} */
    private static function whereFor(LinkSearch $search): array
    {
        $where  = ['links.verified = 1', 'links.deleted = 0'];
        $params = [];

        if (!$search->includePrivate) {
            $where[] = 'links.is_private = 0';
        }
        if ($search->accountId !== null) {
            $where[]  = 'links.account_id = ?';
            $params[] = $search->accountId;
        }
        if ($search->siteId !== null) {
            $where[]  = 'links.site_id = ?';
            $params[] = $search->siteId;
        }
        if ($search->pageIds !== null) {
            $where[] = 'links.page_id IN (' . Database::placeholders($search->pageIds) . ')';
            array_push($params, ...$search->pageIds);
        }
        if ($search->types !== [] || $search->includeUnlabelled) {
            $typeClauses = [];
            if ($search->types !== []) {
                $typeClauses[] = 'links.type IN (' . Database::placeholders($search->types) . ')';
                array_push($params, ...$search->types);
            }
            if ($search->includeUnlabelled) {
                $typeClauses[] = 'links.type IS NULL';
                $typeClauses[] = 'links.type NOT IN (' . Database::placeholders(Jf2Format::LABELLED_TYPES) . ')';
                array_push($params, ...Jf2Format::LABELLED_TYPES);
            }
            $where[] = '(' . implode(' OR ', $typeClauses) . ')';
        }
        if ($search->createdAfter !== null) {
            $where[]  = 'links.created_at > ?';
            $params[] = $search->createdAfter;
        }
        if ($search->idAfter !== null) {
            $where[]  = 'links.id > ?';
            $params[] = $search->idAfter;
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * A slice of an account's published mentions in id order, for the export:
     * everything after $afterId, private ones included.
     *
     * @return list<Link>
     */
    public function exportBatch(int $accountId, ?int $siteId, int $afterId, int $limit): array
    {
        $params = [$accountId];
        $site   = '';
        if ($siteId !== null) {
            $site     = ' AND links.site_id = ?';
            $params[] = $siteId;
        }
        array_push($params, $afterId, max(1, $limit));

        return $this->many(
            self::SELECT . " WHERE links.account_id = ?$site AND links.verified = 1 AND links.deleted = 0 AND links.id > ? ORDER BY links.id LIMIT ?",
            $params,
        );
    }

    /** @param list<mixed> $params */
    private function first(string $sql, array $params): ?Link
    {
        $row = $this->db->one($sql, $params);

        return $row === null ? null : Link::fromRow($row);
    }

    /**
     * @param  list<mixed> $params
     * @return list<Link>
     */
    private function many(string $sql, array $params): array
    {
        return array_map(Link::fromRow(...), $this->db->all($sql, $params));
    }
}
