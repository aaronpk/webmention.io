<?php

declare(strict_types=1);

namespace Webmention\Storage;

use Webmention\Model\Page;

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
     * Every page, in any account, with one of these URLs. Target lookups in the
     * public API are not scoped to an account.
     *
     * @param  list<string> $hrefs
     * @return list<int>
     */
    public function idsForHrefs(array $hrefs): array
    {
        if ($hrefs === []) {
            return [];
        }

        $rows = $this->db->all(
            'SELECT id FROM pages WHERE href IN (' . Database::placeholders($hrefs) . ')',
            $hrefs,
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }
}
