<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use Redis;
use Webmention\Logging\Log;
use Webmention\Model\Site;
use Webmention\Storage\Database;

/**
 * Removes a site and everything it received.
 *
 * The site is archived first, so it refuses webmentions at once. Then its
 * rows go in batches: links (the bulk, with 14 indexes each), then its page
 * aliases, blocked sources, web hook deliveries and pages, and last the site
 * row itself. A small site finishes inside the request; a large one is left
 * on a Redis list for the workers, which take one batch per turn between
 * webmention jobs. Every step can be repeated, so deleting again finishes an
 * interrupted deletion.
 */
final class SiteDeleter
{
    public const QUEUE = 'webmention:site-purge';

    private const FLAG     = 'webmention:site-deleting:';
    private const FLAG_TTL = 7 * 86400;

    public function __construct(
        private readonly Database $db,
        private readonly Redis $redis,
        private readonly Log $log,
        private readonly float $budgetSeconds = 3.0,
        private readonly int $batch = 2000,
    ) {
    }

    /** @return bool True when the site is already gone; false when the workers will finish it. */
    public function delete(Site $site): bool
    {
        $now = Database::now();
        $this->db->run('UPDATE sites SET archived_at = COALESCE(archived_at, ?), updated_at = ? WHERE id = ?', [$now, $now, $site->id]);
        $this->redis->setex(self::FLAG . $site->id, self::FLAG_TTL, '1');
        $this->log->info("Deleting site {$site->id} ({$site->domain}) of account {$site->accountId}");

        $until = microtime(true) + $this->budgetSeconds;
        do {
            if ($this->purgeStep($site->id)) {
                return true;
            }
        } while (microtime(true) < $until);

        $this->redis->lPush(self::QUEUE, (string) $site->id);

        return false;
    }

    public function isDeleting(int $siteId): bool
    {
        return (bool) $this->redis->exists(self::FLAG . $siteId);
    }

    /** One batch of a site's removal. True once the site row itself is gone. */
    public function purgeStep(int $siteId): bool
    {
        $removed = $this->db->run('DELETE FROM links WHERE site_id = ? LIMIT ' . $this->batch, [$siteId])->rowCount();
        if ($removed >= $this->batch) {
            return false;
        }

        foreach (['page_aliases', 'blocklists', 'webhook_deliveries', 'pages'] as $table) {
            $this->db->run("DELETE FROM `$table` WHERE site_id = ?", [$siteId]);
        }
        $this->db->run('DELETE FROM sites WHERE id = ?', [$siteId]);
        $this->redis->del(self::FLAG . $siteId);
        $this->log->info("Deleted site $siteId");

        return true;
    }

    /**
     * For the workers: one batch of the next pending deletion, which goes to
     * the back of the list if it is not done. Null when nothing is pending.
     */
    public function purgeNext(): ?bool
    {
        $id = $this->redis->rPop(self::QUEUE);
        if (!is_string($id) || (int) $id <= 0) {
            return null;
        }

        $done = $this->purgeStep((int) $id);
        if (!$done) {
            $this->redis->lPush(self::QUEUE, $id);
        }

        return $done;
    }
}
