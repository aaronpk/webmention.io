#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Folds duplicate sites (the same domain on the same account) into one row
 * each, so that 2026-09-14-sites-unique-domain.sql can be applied.
 *
 *   php database/migrations/2026-09-14-dedupe-sites.php            # dry run: prints what would change
 *   php database/migrations/2026-09-14-dedupe-sites.php --apply    # does it, one transaction per group
 *
 * For each group the row with the most pages and links is kept (the oldest
 * on a tie). Every other row's blocklist entries, web hook settings (when the
 * survivor has none), pages and links are moved to it, pages the survivor
 * already has are folded together, and the row is deleted. See
 * Webmention\Storage\SiteDeduplicator.
 *
 * Safe to run while either app is live. Run it again afterwards: it should
 * report no groups.
 */

use Webmention\Bootstrap;
use Webmention\Storage\Database;
use Webmention\Storage\SiteDeduplicator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$apply = in_array('--apply', array_slice($argv, 1), true);

$db     = Database::connect(Bootstrap::config());
$dedupe = new SiteDeduplicator($db);
$groups = $dedupe->groups();

printf("%s: %d duplicate site groups in database \"%s\"\n\n", $apply ? 'APPLYING' : 'DRY RUN', count($groups), (string) $db->value('SELECT DATABASE()'));

$deleted = $merged = 0;
foreach ($groups as $group) {
    $notes = $dedupe->merge($group, dryRun: !$apply);

    printf("%s (account %d): keep #%d [%d pages, %d links]; %s\n",
        $group['domain'],
        $group['account_id'],
        (int) $group['keep']['id'],
        (int) $group['keep']['pages'],
        (int) $group['keep']['links'],
        implode('; ', $notes),
    );

    $deleted += count($group['drop']);
    foreach ($group['drop'] as $dup) {
        if ((int) $dup['pages'] > 0 || (int) $dup['links'] > 0) {
            $merged++;
        }
    }
}

printf("\n%d groups, %d rows %s (%d of them with pages or links merged into the survivor)\n",
    count($groups), $deleted, $apply ? 'deleted' : 'to delete', $merged);

if ($apply) {
    $remaining = count($dedupe->groups());
    printf("%d duplicate groups remain\n", $remaining);
    exit($remaining === 0 ? 0 : 1);
}

if ($groups !== []) {
    echo "\nNothing was changed. Re-run with --apply to do this.\n";
}
