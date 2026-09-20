<?php
/**
 * The admin section's own tab strip.
 *
 * @var string $adminTab Which one is current: overview, activity, sources, accounts, lookup.
 */
$adminTabs = [
    'overview' => ['/admin', 'Overview'],
    'activity' => ['/admin/activity', 'Activity'],
    'sources'  => ['/admin/sources', 'Sources'],
    'accounts' => ['/admin/accounts', 'Accounts'],
    'lookup'   => ['/admin/lookup', 'Lookup'],
];
?>
<nav class="tabs" aria-label="Admin">
    <?php foreach ($adminTabs as $key => [$href, $label]) { ?>
        <a href="<?= $href ?>"<?= $key === $adminTab ? ' aria-current="page"' : '' ?>><?= $label ?></a>
    <?php } ?>
</nav>
