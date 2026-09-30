#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Puts a bare hostname back in sites.domain, so the rows that hold a whole
 * URL or an underscore-encoded path can verify and receive again.
 *
 *   php database/migrations/2026-09-29-clean-site-domains.php            # dry run: prints what would change
 *   php database/migrations/2026-09-29-clean-site-domains.php --apply    # does it, one transaction per row
 *
 * Two shapes, both from the Ruby app. Its add-a-site form wrote the field
 * straight to the column (controllers/controller.rb:75), giving
 * "https://alexsci.com/blog/" and "HTTPS://cathydutton.co.uk". And
 * controllers/auth.rb:75 made an account name out of an IndieAuth profile URL
 * with `.gsub(/\//, '_')` so a path stayed one segment of
 * "/:username/webmention"; controllers/controller.rb:62 copied that into the
 * first site, giving "www.samnir.com_zh_".
 *
 * A row is renamed where the name is free, folded into the account's own row
 * where it is not, and left alone when nothing can be made of it or when a
 * duplicate turns out to hold webmentions. Renaming a path-encoded row also
 * clears its verification: IndieAuth proved a path, not the host, so
 * tools/verify-sites should decide that afresh. Accounts are never touched —
 * the underscore form is their published endpoint name.
 *
 * Requires ext-intl, for the internationalised domains.
 *
 * Safe to run while the site is live; one transaction per row. Run it again
 * afterwards: only the rows it could not resolve should remain.
 */

use Webmention\Bootstrap;
use Webmention\Storage\Database;
use Webmention\Storage\SiteDomainCleanup;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$apply   = in_array('--apply', array_slice($argv, 1), true);
$db      = Database::connect(Bootstrap::config());
$cleanup = new SiteDomainCleanup($db);
$plan    = $cleanup->plan();

printf(
    "%s: %d to rename, %d to fold, %d that cannot be resolved, in database \"%s\"\n\n",
    $apply ? 'APPLYING' : 'DRY RUN',
    count($plan[SiteDomainCleanup::RENAME]),
    count($plan[SiteDomainCleanup::FOLD]),
    count($plan[SiteDomainCleanup::STUCK]),
    (string) $db->value('SELECT DATABASE()'),
);

foreach ([SiteDomainCleanup::RENAME => 'Renaming', SiteDomainCleanup::FOLD => 'Folding'] as $kind => $heading) {
    if ($plan[$kind] === []) {
        continue;
    }

    echo "$heading\n";
    foreach ($plan[$kind] as $item) {
        echo '  ', $cleanup->apply($item, dryRun: !$apply), "\n";
    }
    echo "\n";
}

if ($plan[SiteDomainCleanup::STUCK] !== []) {
    echo "Left alone\n";
    foreach ($plan[SiteDomainCleanup::STUCK] as $item) {
        printf("  site #%d %s: %s\n", $item['id'], $item['domain'] === '' ? '(empty)' : $item['domain'], $item['why']);
    }
    echo "\n";
}

if ($apply) {
    $after     = $cleanup->plan();
    $remaining = count($after[SiteDomainCleanup::RENAME]) + count($after[SiteDomainCleanup::FOLD]);

    printf("%d rows still to clean up\n", $remaining);

    exit($remaining === 0 ? 0 : 1);
}

if ($plan[SiteDomainCleanup::RENAME] !== [] || $plan[SiteDomainCleanup::FOLD] !== []) {
    echo "Nothing was changed. Re-run with --apply to do this.\n";
}
