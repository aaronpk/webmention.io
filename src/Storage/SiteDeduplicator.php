<?php

declare(strict_types=1);

namespace Webmention\Storage;

use Throwable;

/**
 * Folds duplicate `sites` rows (the same domain on the same account) into one.
 *
 * The Ruby app's add-site form never looked for an existing row, so a double
 * submit, or re-adding the domain the settings page had just created, left a
 * second site. Each group keeps the row with the most pages and links (the
 * oldest on a tie); every other row's blocklist entries, web hook settings,
 * pages and links move to it, and the row is deleted. Pages that exist on
 * both sites (same href) are folded into the survivor's page.
 *
 * Used by database/migrations/2026-09-14-dedupe-sites.php, which must run
 * before the unique index on (account_id, domain) can be added.
 */
final class SiteDeduplicator
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Every duplicate group, with the row to keep and the rows to fold into it.
     *
     * @return list<array{account_id: int, domain: string, keep: array<string, mixed>, drop: list<array<string, mixed>>}>
     */
    public function groups(): array
    {
        $pairs = $this->db->all(
            'SELECT account_id, domain FROM sites WHERE domain IS NOT NULL
                GROUP BY account_id, domain HAVING COUNT(*) > 1 ORDER BY account_id, domain',
        );

        $groups = [];
        foreach ($pairs as $pair) {
            $sites = $this->db->all(
                'SELECT s.*,
                    (SELECT COUNT(*) FROM pages WHERE site_id = s.id) AS pages,
                    (SELECT COUNT(*) FROM links WHERE site_id = s.id) AS links,
                    (SELECT COUNT(*) FROM blocklists WHERE site_id = s.id) AS blocks
                 FROM sites s WHERE s.account_id = ? AND s.domain = ? ORDER BY s.id',
                [(int) $pair['account_id'], (string) $pair['domain']],
            );

            usort($sites, static function (array $a, array $b): int {
                return ((int) $b['pages'] + (int) $b['links']) <=> ((int) $a['pages'] + (int) $a['links'])
                    ?: (int) $a['id'] <=> (int) $b['id'];
            });

            $groups[] = [
                'account_id' => (int) $pair['account_id'],
                'domain'     => (string) $pair['domain'],
                'keep'       => $sites[0],
                'drop'       => array_slice($sites, 1),
            ];
        }

        return $groups;
    }

    /**
     * Fold one group into its survivor. With $dryRun nothing is written; the
     * returned notes describe what would happen either way.
     *
     * @param  array{account_id: int, domain: string, keep: array<string, mixed>, drop: list<array<string, mixed>>} $group
     * @return list<string>
     */
    public function merge(array $group, bool $dryRun = true): array
    {
        $keep  = $group['keep'];
        $notes = [];

        if (!$dryRun) {
            $this->db->pdo()->beginTransaction();
        }

        try {
            foreach ($group['drop'] as $dup) {
                $notes = [...$notes, ...$this->fold($keep, $dup, $dryRun)];
            }

            if (!$dryRun) {
                $this->db->pdo()->commit();
            }
        } catch (Throwable $e) {
            if (!$dryRun && $this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }

            throw $e;
        }

        return $notes;
    }

    /**
     * @param  array<string, mixed> $keep Passed by reference so a copied web hook is seen by later duplicates.
     * @param  array<string, mixed> $dup
     * @return list<string>
     */
    private function fold(array &$keep, array $dup, bool $dryRun): array
    {
        $keepId = (int) $keep['id'];
        $dupId  = (int) $dup['id'];
        $notes  = [];

        // Blocked sources move over, unless the survivor already blocks them.
        $moved = $dropped = 0;
        foreach ($this->db->all('SELECT id, source FROM blocklists WHERE site_id = ?', [$dupId]) as $block) {
            $already = $this->db->value(
                'SELECT 1 FROM blocklists WHERE site_id = ? AND source = ? LIMIT 1',
                [$keepId, (string) $block['source']],
            ) !== null;

            if ($already) {
                $dropped++;
                if (!$dryRun) {
                    $this->db->run('DELETE FROM blocklists WHERE id = ?', [(int) $block['id']]);
                }
            } else {
                $moved++;
                if (!$dryRun) {
                    $this->db->run('UPDATE blocklists SET site_id = ? WHERE id = ?', [$keepId, (int) $block['id']]);
                }
            }
        }
        if ($moved > 0 || $dropped > 0) {
            $notes[] = "moved $moved blocklist rows" . ($dropped > 0 ? " ($dropped already on the survivor)" : '');
        }

        // A web hook configured on the duplicate is kept if the survivor has none.
        if (self::blank($keep['callback_url'] ?? null) && !self::blank($dup['callback_url'] ?? null)) {
            if (!$dryRun) {
                $this->db->update('sites', $keepId, [
                    'callback_url'    => $dup['callback_url'],
                    'callback_secret' => $dup['callback_secret'],
                    'updated_at'      => Database::now(),
                ]);
            }
            $keep['callback_url']    = $dup['callback_url'];
            $keep['callback_secret'] = $dup['callback_secret'];
            $notes[] = 'copied web hook ' . $dup['callback_url'];
        }

        // Pages and links come along; a page the survivor already has is folded into it.
        if ((int) $dup['pages'] > 0 || (int) $dup['links'] > 0) {
            $folded = 0;
            foreach ($this->db->all('SELECT id, href FROM pages WHERE site_id = ?', [$dupId]) as $page) {
                $existing = $this->db->value(
                    'SELECT id FROM pages WHERE site_id = ? AND href = ? ORDER BY id LIMIT 1',
                    [$keepId, (string) $page['href']],
                );
                if ($existing === null) {
                    continue;
                }
                $folded++;
                if (!$dryRun) {
                    $this->db->run('UPDATE links SET page_id = ?, site_id = ? WHERE page_id = ?', [(int) $existing, $keepId, (int) $page['id']]);
                    $this->db->run('DELETE FROM pages WHERE id = ?', [(int) $page['id']]);
                }
            }

            if (!$dryRun) {
                $this->db->run('UPDATE pages SET site_id = ? WHERE site_id = ?', [$keepId, $dupId]);
                $this->db->run('UPDATE links SET site_id = ? WHERE site_id = ?', [$keepId, $dupId]);
            }

            $notes[] = sprintf(
                'merged %d pages (%d folded into existing pages) and %d links',
                (int) $dup['pages'],
                $folded,
                (int) $dup['links'],
            );
        }

        if (!$dryRun) {
            $this->db->run('DELETE FROM sites WHERE id = ?', [$dupId]);
        }
        $notes[] = "deleted site #$dupId";

        return $notes;
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }
}
