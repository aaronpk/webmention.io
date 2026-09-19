<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use Webmention\Model\Site;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\SiteRepository;

/**
 * Checks sites that were added before proof of ownership was required, and
 * records the outcome. Run from tools/verify-sites, normally by cron.
 *
 * Unverified sites are checked in SiteRepository::unverifiedToCheck() order.
 * A site that advertises its account's endpoint on its home page or one of
 * its recently mentioned pages is marked verified; otherwise the failure is
 * recorded and it is tried again on a later run. Verified sites are only
 * re-checked when asked, and are not downgraded for being down or missing
 * the tag; the one exception, a domain that now names another account's
 * endpoint, is SiteOwnership's.
 */
final class SiteRecheck
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly AccountRepository $accounts,
        private readonly SiteOwnership $ownership,
        private readonly int $pauseMs = 250,
    ) {
    }

    /**
     * @return list<string> One line per site checked.
     */
    public function run(int $limit, bool $dryRun = true, ?int $recheckVerifiedOlderThanDays = null): array
    {
        $sites = $this->sites->unverifiedToCheck($limit);

        if ($recheckVerifiedOlderThanDays !== null && count($sites) < $limit) {
            $sites = [...$sites, ...$this->sites->verifiedToRecheck($recheckVerifiedOlderThanDays, $limit - count($sites))];
        }

        $lines = [];
        foreach ($sites as $i => $site) {
            if ($i > 0 && $this->pauseMs > 0) {
                usleep($this->pauseMs * 1000);
            }
            $lines[] = $this->check($site, $dryRun);
        }

        return $lines;
    }

    public function check(Site $site, bool $dryRun): string
    {
        $account = $this->accounts->find($site->accountId);
        $label   = sprintf('#%d %s (account %d%s)', $site->id, $site->domain, $site->accountId, $site->isVerified() ? ', verified' : '');

        if ($account === null || $site->domain === null) {
            return "$label: no account; skipped";
        }

        $problem = $this->ownership->check($site, $account, $this->sites->recentPageHrefs($site->id), $dryRun);

        if ($problem === null) {
            return "$label: verified";
        }

        $now = $dryRun ? $site : ($this->sites->find($site->id) ?? $site);
        if ($site->isVerified() && !$now->isVerified()) {
            return "$label: unverified: " . $now->verificationError;
        }

        return "$label: not verified: $problem";
    }
}
