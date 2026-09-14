<?php

declare(strict_types=1);

namespace Webmention\Storage;

use PDOException;
use Webmention\Model\Page;
use Webmention\Webmention\TargetResolver;

final class PageRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function find(int $id): ?Page
    {
        $row = $this->db->one('SELECT * FROM pages WHERE id = ?', [$id]);

        return $row === null ? null : Page::fromRow($row);
    }

    public function findBySiteAndHref(int $siteId, string $href): ?Page
    {
        $row = $this->db->one(
            'SELECT * FROM pages WHERE site_id = ? AND href = ? ORDER BY id LIMIT 1',
            [$siteId, $href],
        );

        return $row === null ? null : Page::fromRow($row);
    }

    /** The page an alias URL (an old URL, a fragment URL) points at. */
    public function findByAlias(int $siteId, string $href): ?Page
    {
        $row = $this->db->one(
            'SELECT pages.* FROM page_aliases JOIN pages ON pages.id = page_aliases.page_id
                WHERE page_aliases.site_id = ? AND page_aliases.href = ? LIMIT 1',
            [$siteId, $href],
        );

        return $row === null ? null : Page::fromRow($row);
    }

    /** Remember that $href leads to $pageId. Recording it again changes nothing. */
    public function addAlias(int $siteId, string $href, int $pageId): void
    {
        try {
            $this->db->insert('page_aliases', [
                'site_id'    => $siteId,
                'href'       => $href,
                'page_id'    => $pageId,
                'created_at' => Database::now(),
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    /** @return list<array{href: string, page_id: int}> */
    public function aliasesForPage(int $pageId): array
    {
        return array_map(
            static fn (array $row): array => ['href' => (string) $row['href'], 'page_id' => (int) $row['page_id']],
            $this->db->all('SELECT href, page_id FROM page_aliases WHERE page_id = ? ORDER BY id', [$pageId]),
        );
    }

    public function create(int $accountId, int $siteId, string $href): Page
    {
        $now = Database::now();
        $id  = $this->db->insert('pages', [
            'account_id' => $accountId,
            'site_id'    => $siteId,
            'href'       => $href,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($id) ?? throw new \RuntimeException('Page vanished after insert.');
    }

    public function describe(int $id, ?string $type, ?string $name): void
    {
        $this->db->update('pages', $id, ['type' => $type, 'name' => $name, 'updated_at' => Database::now()]);
    }

    /**
     * Every page, in any account, that one of these URLs names, directly or
     * through an alias. Fragments are ignored. Target lookups in the public
     * API are not scoped to an account.
     *
     * @param  list<string> $hrefs
     * @return list<int>
     */
    public function idsForHrefs(array $hrefs): array
    {
        if ($hrefs === []) {
            return [];
        }

        // Both the fragment-less form and the form as given: pages filed
        // under a fragment URL before fragments were dropped still match.
        $forms = array_values(array_unique([...array_map(TargetResolver::key(...), $hrefs), ...$hrefs]));
        $in    = Database::placeholders($forms);

        $rows = $this->db->all(
            "SELECT id FROM pages WHERE href IN ($in) UNION SELECT page_id FROM page_aliases WHERE href IN ($in)",
            [...$forms, ...$forms],
        );
        $ids = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['id'], $rows)));

        return $ids === [] ? [] : $this->onlyTrustedSites($ids);
    }

    /**
     * Drop pages on a site that has not proved it owns its domain while
     * another account holds a verified site for that domain. Uncontested
     * unverified sites keep appearing; a squatter disappears from public
     * results the moment the real owner verifies.
     *
     * @param  list<int> $pageIds
     * @return list<int>
     */
    private function onlyTrustedSites(array $pageIds): array
    {
        $rows = $this->db->all(
            'SELECT p.id FROM pages p JOIN sites s ON s.id = p.site_id
                WHERE p.id IN (' . Database::placeholders($pageIds) . ')
                AND (s.verified_at IS NOT NULL OR NOT EXISTS (
                    SELECT 1 FROM sites v WHERE v.domain = s.domain AND v.account_id <> s.account_id AND v.verified_at IS NOT NULL))',
            $pageIds,
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Fold one page into another: its mentions move over (a mention from a
     * source the other page already has is dropped rather than duplicated),
     * its aliases follow, its own URL becomes an alias, and it is deleted.
     *
     * @return int How many mentions moved.
     */
    public function merge(Page $from, Page $into): int
    {
        if ($from->id === $into->id) {
            return 0;
        }

        $this->db->run(
            'DELETE l FROM links l JOIN links k ON k.page_id = ? AND k.href = l.href WHERE l.page_id = ?',
            [$into->id, $from->id],
        );
        $moved = $this->db->run(
            'UPDATE links SET page_id = ?, site_id = ?, account_id = ?, updated_at = ? WHERE page_id = ?',
            [$into->id, $into->siteId, $into->accountId, Database::now(), $from->id],
        )->rowCount();

        $this->db->run('UPDATE page_aliases SET page_id = ? WHERE page_id = ?', [$into->id, $from->id]);
        $this->db->run('DELETE FROM pages WHERE id = ?', [$from->id]);
        $this->addAlias($from->siteId, (string) $from->href, $into->id);

        return $moved;
    }
}
