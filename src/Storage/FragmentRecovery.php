<?php

declare(strict_types=1);

namespace Webmention\Storage;

/**
 * Fills in links.target_fragment for webmentions received before the fragment
 * was recorded, from a map taken out of a backup made before the fragment
 * pages were folded (2026-09-15-fold-fragment-pages.php).
 *
 * Before the fold, a mention sent to ".../gallery#photo-2" had its own page
 * row, so the backup's links.page_id says which fragment each one arrived at.
 * The fold rewrote links.page_id and kept the fragment URL as a page alias,
 * which is what makes the map safe to apply: a row is only filled in when
 * that alias still points at the page the webmention sits on now. Link ids
 * never changed, so they are the key.
 */
final class FragmentRecovery
{
    /** Rows per statement batch. */
    public const BATCH = 500;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * The map, read from prefold_links and prefold_pages loaded out of the
     * backup into this database.
     *
     * @return list<array{id: int, fragment: string, url: string}>
     */
    public function fromPrefoldTables(int $afterId = 0, int $limit = 10000): array
    {
        $rows = $this->db->all(
            "SELECT l.id, p.href AS url, SUBSTRING(p.href, LOCATE('#', p.href) + 1) AS fragment
                FROM prefold_links l JOIN prefold_pages p ON p.id = l.page_id
                WHERE p.href LIKE '%#%' AND LOCATE('#', p.href) < CHAR_LENGTH(p.href) AND l.id > ?
                ORDER BY l.id LIMIT ?",
            [$afterId, $limit],
        );

        return array_map(
            static fn (array $row): array => [
                'id'       => (int) $row['id'],
                'fragment' => mb_substr((string) $row['fragment'], 0, 255),
                'url'      => (string) $row['url'],
            ],
            $rows,
        );
    }

    /**
     * Fill in the fragment for each mapped webmention that still has none and
     * whose fragment URL is still an alias of its page. Leaves updated_at
     * alone: this is a backfill, not a change to the webmention.
     *
     * @param  list<array{id: int, fragment: string, url: string}> $rows
     * @return array{filled: int, already: int, gone: int, mismatch: int, notes: list<string>}
     */
    public function apply(array $rows, bool $apply): array
    {
        $result = ['filled' => 0, 'already' => 0, 'gone' => 0, 'mismatch' => 0, 'notes' => []];

        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $byId = [];
            foreach ($chunk as $row) {
                $byId[$row['id']] = $row;
            }

            $ids     = array_keys($byId);
            $current = $this->db->all(
                'SELECT id, page_id, target_fragment FROM links WHERE id IN (' . Database::placeholders($ids) . ')',
                $ids,
            );

            $fill = [];
            foreach ($current as $link) {
                $row = $byId[(int) $link['id']];
                unset($byId[(int) $link['id']]);

                if ($link['target_fragment'] !== null) {
                    $result['already']++;
                    continue;
                }

                $aliasPage = $this->db->value('SELECT page_id FROM page_aliases WHERE href = ? LIMIT 1', [$row['url']]);
                if ($aliasPage === null || (int) $aliasPage !== (int) $link['page_id']) {
                    $result['mismatch']++;
                    if (count($result['notes']) < 5) {
                        $result['notes'][] = sprintf('webmention %d left alone: %s is not an alias of the page it sits on', $row['id'], $row['url']);
                    }
                    continue;
                }

                $fill[$row['fragment']][] = (int) $link['id'];
            }

            // Ids in the map with no row here were deleted since the backup.
            $result['gone'] += count($byId);

            foreach ($fill as $fragment => $linkIds) {
                $result['filled'] += count($linkIds);
                if ($apply) {
                    $this->db->run(
                        'UPDATE links SET target_fragment = ? WHERE target_fragment IS NULL AND id IN (' . Database::placeholders($linkIds) . ')',
                        [(string) $fragment, ...$linkIds],
                    );
                }
            }
        }

        return $result;
    }
}
