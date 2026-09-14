<?php

declare(strict_types=1);

namespace Webmention\Storage;

use Throwable;
use Webmention\Model\Page;
use Webmention\Webmention\TargetResolver;

/**
 * Folds pages filed under a #fragment URL into the page for the URL without
 * it. A fragment never names a different page, but the Ruby app filed
 * mentions under the target exactly as sent, so a like of ".../post#comments"
 * was invisible to a query for ".../post" (issue 106).
 *
 * Used by database/migrations/2026-09-15-fold-fragment-pages.php.
 */
final class FragmentFolder
{
    public function __construct(private readonly Database $db, private readonly PageRepository $pages)
    {
    }

    /** @return list<Page> Oldest first. */
    public function fragmentPages(): array
    {
        return array_map(Page::fromRow(...), $this->db->all("SELECT * FROM pages WHERE href LIKE '%#%' ORDER BY id"));
    }

    /** Fold one page; with $dryRun nothing is written. Returns a one-line description. */
    public function fold(Page $page, bool $dryRun = true): string
    {
        $base = TargetResolver::key((string) $page->href);
        $into = $this->pages->findBySiteAndHref($page->siteId, $base) ?? $this->pages->findByAlias($page->siteId, $base);
        $links = (int) $this->db->value('SELECT COUNT(*) FROM links WHERE page_id = ?', [$page->id]);

        $what = sprintf(
            '%s: %d mentions into %s',
            $page->href,
            $links,
            $into === null ? "new page $base" : "page #{$into->id}",
        );

        if ($dryRun) {
            return $what;
        }

        $this->db->pdo()->beginTransaction();
        try {
            $into ??= $this->pages->create($page->accountId, $page->siteId, $base);
            $this->pages->merge($page, $into);
            $this->db->pdo()->commit();
        } catch (Throwable $e) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }

            throw $e;
        }

        return $what;
    }
}
