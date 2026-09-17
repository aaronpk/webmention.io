<?php

declare(strict_types=1);

namespace Webmention\Storage;

use Webmention\Format\Url;
use Webmention\Model\Page;

/**
 * Puts right what DataAudit finds, computed from the data itself so it can
 * run against production directly: a dry run first, then --apply.
 *
 * Every repair is idempotent and works in batches, so an interrupted run is
 * finished by running it again, and a second run finds nothing to do.
 */
final class DataRepair
{
    /** Rows or groups per batch. */
    public const BATCH = 1000;

    public function __construct(
        private readonly Database $db,
        private readonly PageRepository $pages,
        private readonly SiteRepository $sites,
    ) {
    }

    /**
     * @param  list<string>|null $only Keys to run, or null for all of them.
     * @return list<array{key: string, fixed: int, notes: list<string>}>
     */
    public function run(bool $apply, ?array $only = null, int $limit = PHP_INT_MAX): array
    {
        $repairs = [
            'orphan_links'           => fn (): array => $this->orphanLinks($apply, $limit),
            'duplicate_pages'        => fn (): array => $this->duplicatePages($apply, $limit),
            'page_missing_site'      => fn (): array => $this->pagesWithMissingSite($apply, $limit),
            'page_account_mismatch'  => fn (): array => $this->pageAccountMismatch($apply, $limit),
            'site_missing_account'   => fn (): array => $this->sitesWithMissingAccount($apply, $limit),
            'alias_missing_page'     => fn (): array => $this->aliasesWithMissingPage($apply, $limit),
            'blocklist_missing_site' => fn (): array => $this->blocksWithMissingSite($apply, $limit),
        ];

        $out = [];
        foreach ($repairs as $key => $repair) {
            if ($only !== null && !in_array($key, $only, true)) {
                continue;
            }
            [$fixed, $notes] = $repair();
            $out[] = ['key' => $key, 'fixed' => $fixed, 'notes' => $notes];
        }

        return $out;
    }

    /**
     * Webmentions left pointing at a site that no longer exists (site_id 0 on
     * the oldest rows). Their page says which site they belong to, so they
     * become visible to that account again.
     *
     * @return array{int, list<string>}
     */
    private function orphanLinks(bool $apply, int $limit): array
    {
        $stranded = (int) $this->db->value(
            'SELECT COUNT(*) FROM links l LEFT JOIN sites bad ON bad.id = l.site_id
                LEFT JOIN pages p ON p.id = l.page_id LEFT JOIN sites s ON s.id = p.site_id
                LEFT JOIN accounts a ON a.id = s.account_id
                WHERE bad.id IS NULL AND (p.id IS NULL OR s.id IS NULL OR a.id IS NULL)',
        );
        $notes = $stranded === 0 ? [] : ["$stranded left alone: their page is gone too, so nothing can say where they belong"];

        if (!$apply) {
            $waiting = (int) $this->db->value(
                'SELECT COUNT(*) FROM links l LEFT JOIN sites bad ON bad.id = l.site_id JOIN pages p ON p.id = l.page_id
                    JOIN sites s ON s.id = p.site_id JOIN accounts a ON a.id = s.account_id WHERE bad.id IS NULL',
            );

            return [min($waiting, $limit), $notes];
        }

        $fixed = 0;

        // Asking for "links whose site is missing" directly walks the whole
        // table: 30 to 45 seconds per batch of 1,000. The site ids that no
        // site has are only a handful, so each one's rows are read through
        // the site index instead, which answers in milliseconds. Writes go a
        // batch at a time, one statement per (site, account) group, because
        // committing each row separately was almost as slow.
        foreach ([...$this->brokenSiteIds(), null] as $brokenId) {
            $match  = $brokenId === null ? 'l.site_id IS NULL' : 'l.site_id = ?';
            $prefix = $brokenId === null ? [] : [$brokenId];
            $after  = 0;

            while ($fixed < $limit) {
                $rows = $this->db->all(
                    "SELECT l.id, p.site_id, p.account_id FROM links l
                        JOIN pages p ON p.id = l.page_id
                        JOIN sites s ON s.id = p.site_id
                        JOIN accounts a ON a.id = s.account_id
                        WHERE $match AND l.id > ? ORDER BY l.id LIMIT ?",
                    [...$prefix, $after, min(self::BATCH, $limit - $fixed)],
                );
                if ($rows === []) {
                    break;
                }

                $groups = [];
                foreach ($rows as $row) {
                    $after = (int) $row['id'];
                    $groups[$row['site_id'] . ':' . $row['account_id']][] = (int) $row['id'];
                }

                $this->db->pdo()->beginTransaction();
                foreach ($groups as $target => $ids) {
                    [$siteId, $accountId] = array_map('intval', explode(':', (string) $target));
                    $this->db->run(
                        'UPDATE links SET site_id = ?, account_id = ?, updated_at = ? WHERE id IN (' . Database::placeholders($ids) . ')',
                        [$siteId, $accountId, Database::now(), ...$ids],
                    );
                    $fixed += count($ids);
                }
                $this->db->pdo()->commit();
            }
        }

        return [$fixed, $notes];
    }

    /**
     * Site ids that webmentions point at but no site has. Reading the
     * distinct values costs one pass over the site index.
     *
     * @return list<int>
     */
    private function brokenSiteIds(): array
    {
        $used     = array_map('intval', array_column($this->db->all('SELECT DISTINCT site_id FROM links'), 'site_id'));
        $existing = array_map('intval', array_column($this->db->all('SELECT id FROM sites'), 'id'));

        return array_values(array_diff($used, $existing));
    }

    /**
     * One URL, several page rows on the same site: fold them into the row
     * with the most webmentions, oldest on a tie.
     *
     * @return array{int, list<string>}
     */
    private function duplicatePages(bool $apply, int $limit): array
    {
        $fixed = 0;
        $notes = [];

        if (!$apply) {
            $groups = (int) $this->db->value('SELECT COUNT(*) FROM (SELECT site_id, href FROM pages WHERE href IS NOT NULL GROUP BY site_id, href HAVING COUNT(*) > 1) x');

            return [min($groups, $limit), $notes];
        }

        while ($fixed < $limit) {
            $groups = $this->db->all(
                'SELECT site_id, href FROM pages WHERE href IS NOT NULL GROUP BY site_id, href HAVING COUNT(*) > 1 LIMIT ?',
                [min(self::BATCH, $limit - $fixed)],
            );
            if ($groups === []) {
                break;
            }

            foreach ($groups as $group) {
                $rows = $this->db->all(
                    'SELECT p.id, (SELECT COUNT(*) FROM links l WHERE l.page_id = p.id) AS links
                        FROM pages p WHERE p.site_id = ? AND p.href = ? ORDER BY links DESC, p.id',
                    [(int) $group['site_id'], (string) $group['href']],
                );
                $keep = array_shift($rows);
                if ($keep === null || $rows === []) {
                    continue;
                }

                // Each group folded atomically, so an interrupted run leaves
                // no half-merged page behind.
                $this->db->pdo()->beginTransaction();
                $into = $this->pages->find((int) $keep['id']);
                foreach ($rows as $row) {
                    $from = $this->pages->find((int) $row['id']);
                    if ($from !== null && $into instanceof Page) {
                        $this->pages->merge($from, $into);
                    }
                }
                // merge() records the old URL as an alias, which for a
                // duplicate is the surviving page's own URL.
                $this->db->run('DELETE FROM page_aliases WHERE site_id = ? AND href = ? AND page_id = ?', [(int) $group['site_id'], (string) $group['href'], (int) $keep['id']]);
                $this->db->pdo()->commit();
                $fixed++;
            }
        }

        return [$fixed, $notes];
    }

    /**
     * A page whose site is gone: re-file it onto the account's site for that
     * host, folding it into an existing page for the same URL if there is one.
     *
     * @return array{int, list<string>}
     */
    private function pagesWithMissingSite(bool $apply, int $limit): array
    {
        $fixed = 0;
        $notes = [];

        $rows = $this->db->all(
            'SELECT p.id, p.href, p.account_id FROM pages p LEFT JOIN sites s ON s.id = p.site_id WHERE s.id IS NULL LIMIT ?',
            [min(self::BATCH, $limit)],
        );

        foreach ($rows as $row) {
            $href = (string) ($row['href'] ?? '');
            $host = $href === '' ? null : Url::host($href);
            $site = $host === null ? null : $this->sites->findByAccountAndDomain((int) $row['account_id'], $host);

            if ($site === null) {
                // Most of these are old redirector and shortener URLs whose
                // mentions are long gone: an empty page row with no site is
                // just litter. One that still holds webmentions is kept, so
                // nobody's data disappears on a guess.
                $held = (int) $this->db->value('SELECT COUNT(*) FROM links WHERE page_id = ?', [(int) $row['id']]);
                if ($held > 0) {
                    $notes[] = sprintf('page %d (%s) left alone: it holds %d webmentions and account %s has no site for %s', $row['id'], $href, $held, $row['account_id'], $host ?? 'that URL');
                    continue;
                }
                if ($apply) {
                    $this->db->run('DELETE FROM pages WHERE id = ?', [(int) $row['id']]);
                }
                $fixed++;
                continue;
            }

            if ($apply) {
                $existing = $this->pages->findBySiteAndHref($site->id, $href);
                $page     = $this->pages->find((int) $row['id']);

                $this->db->pdo()->beginTransaction();
                if ($existing !== null && $page !== null && $existing->id !== $page->id) {
                    $this->pages->merge($page, $existing);
                } else {
                    $this->db->run('UPDATE pages SET site_id = ?, account_id = ? WHERE id = ?', [$site->id, $site->accountId, (int) $row['id']]);
                    $this->db->run('UPDATE links SET site_id = ?, account_id = ?, updated_at = ? WHERE page_id = ?', [$site->id, $site->accountId, Database::now(), (int) $row['id']]);
                }
                $this->db->pdo()->commit();
            }
            $fixed++;
        }

        return [$fixed, $notes];
    }

    /** @return array{int, list<string>} */
    private function pageAccountMismatch(bool $apply, int $limit): array
    {
        $rows = $this->db->all(
            'SELECT p.id, s.account_id FROM pages p JOIN sites s ON s.id = p.site_id WHERE p.account_id <> s.account_id LIMIT ?',
            [min(self::BATCH, $limit)],
        );

        if ($apply && $rows !== []) {
            $this->db->pdo()->beginTransaction();
            foreach ($rows as $row) {
                $this->db->run('UPDATE pages SET account_id = ? WHERE id = ?', [(int) $row['account_id'], (int) $row['id']]);
                $this->db->run('UPDATE links SET account_id = ?, updated_at = ? WHERE page_id = ?', [(int) $row['account_id'], Database::now(), (int) $row['id']]);
            }
            $this->db->pdo()->commit();
        }

        return [count($rows), []];
    }

    /**
     * A site whose account is gone. One holding pages or webmentions is left
     * alone: deleting it would take someone's data with it.
     *
     * @return array{int, list<string>}
     */
    private function sitesWithMissingAccount(bool $apply, int $limit): array
    {
        $fixed = 0;
        $notes = [];

        $rows = $this->db->all(
            'SELECT s.id, s.domain,
                    (SELECT COUNT(*) FROM pages p WHERE p.site_id = s.id) AS pages,
                    (SELECT COUNT(*) FROM links l WHERE l.site_id = s.id) AS links
                FROM sites s LEFT JOIN accounts a ON a.id = s.account_id WHERE a.id IS NULL LIMIT ?',
            [min(self::BATCH, $limit)],
        );

        foreach ($rows as $row) {
            if ((int) $row['pages'] > 0 || (int) $row['links'] > 0) {
                $notes[] = sprintf('site %d (%s) left alone: it still holds %d pages and %d webmentions', $row['id'], $row['domain'], $row['pages'], $row['links']);
                continue;
            }
            if ($apply) {
                $this->db->run('DELETE FROM sites WHERE id = ?', [(int) $row['id']]);
            }
            $fixed++;
        }

        return [$fixed, $notes];
    }

    /** @return array{int, list<string>} */
    private function aliasesWithMissingPage(bool $apply, int $limit): array
    {
        $rows = $this->db->all('SELECT x.id FROM page_aliases x LEFT JOIN pages p ON p.id = x.page_id WHERE p.id IS NULL LIMIT ?', [min(self::BATCH, $limit)]);

        foreach ($rows as $row) {
            if ($apply) {
                $this->db->run('DELETE FROM page_aliases WHERE id = ?', [(int) $row['id']]);
            }
        }

        return [count($rows), []];
    }

    /** @return array{int, list<string>} */
    private function blocksWithMissingSite(bool $apply, int $limit): array
    {
        $rows = $this->db->all('SELECT b.id FROM blocklists b LEFT JOIN sites s ON s.id = b.site_id WHERE s.id IS NULL LIMIT ?', [min(self::BATCH, $limit)]);

        foreach ($rows as $row) {
            if ($apply) {
                $this->db->run('DELETE FROM blocklists WHERE id = ?', [(int) $row['id']]);
            }
        }

        return [count($rows), []];
    }
}
