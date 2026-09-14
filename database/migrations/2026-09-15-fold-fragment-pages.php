#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Folds pages filed under a "#fragment" URL into the page for the URL without
 * it, so mentions of ".../post#comments" show up in queries for ".../post"
 * (issue 106). The fragment URL is kept as an alias, so queries for it still
 * work too. Requires 2026-09-15-page-aliases.sql.
 *
 *   php database/migrations/2026-09-15-fold-fragment-pages.php            # dry run
 *   php database/migrations/2026-09-15-fold-fragment-pages.php --apply
 *
 * Safe to run while the site is live; one transaction per page.
 */

use Webmention\Bootstrap;
use Webmention\Storage\Database;
use Webmention\Storage\FragmentFolder;
use Webmention\Storage\PageRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$apply  = in_array('--apply', array_slice($argv, 1), true);
$db     = Database::connect(Bootstrap::config());
$folder = new FragmentFolder($db, new PageRepository($db));
$pages  = $folder->fragmentPages();

printf("%s: %d pages with a fragment in database \"%s\"\n\n", $apply ? 'APPLYING' : 'DRY RUN', count($pages), (string) $db->value('SELECT DATABASE()'));

foreach ($pages as $page) {
    echo $folder->fold($page, dryRun: !$apply), "\n";
}

if ($apply) {
    $remaining = count($folder->fragmentPages());
    printf("\n%d pages with a fragment remain\n", $remaining);
    exit($remaining === 0 ? 0 : 1);
}

if ($pages !== []) {
    echo "\nNothing was changed. Re-run with --apply to do this.\n";
}
