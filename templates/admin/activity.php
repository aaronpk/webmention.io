<?php
/**
 * How the service has grown. See ServiceActivity::report().
 *
 * @var int         $months
 * @var int         $days
 * @var list<array> $webmentions month, label, count
 * @var list<array> $signups     month, label, accounts, sites
 * @var list<array> $kinds       type, label, count
 * @var int         $total
 */
$adminTab = 'activity';
$peak     = max(1, max(array_column($webmentions, 'count')));
$bars     = count($webmentions);
$width    = $bars * 5 - 1;
$everything = array_sum(array_column($webmentions, 'count'));
?>
<section class="card">
    <h2>Activity</h2>
    <?php require __DIR__ . '/_tabs.php'; ?>

    <h3>Webmentions per month</h3>
    <figure class="sparkline">
        <svg viewBox="0 0 <?= $width ?> 20" preserveAspectRatio="none" role="img" aria-label="Webmentions received per month across the service">
            <?php foreach ($webmentions as $i => $m) { ?>
                <?php $h = $m['count'] === 0 ? 0.5 : max(1, round($m['count'] / $peak * 20, 1)); ?>
                <rect x="<?= $i * 5 ?>" y="<?= 20 - $h ?>" width="4" height="<?= $h ?>"<?= $i === $bars - 1 ? ' class="current"' : '' ?>><title><?= $m['label'] ?>: <?= number_format($m['count']) ?> webmention<?= $m['count'] === 1 ? '' : 's' ?></title></rect>
            <?php } ?>
        </svg>
        <figcaption class="muted small">
            <span><?= $webmentions[0]['label'] ?></span>
            <span><?= number_format($everything) ?> received over <?= $months ?> months</span>
            <span><?= $webmentions[$bars - 1]['label'] ?></span>
        </figcaption>
    </figure>
    <p class="muted small">The last bar is the month in progress. Recomputed hourly.
        <?php if ($months >= 60) { ?><a href="/admin/activity?months=24">Show 24 months</a><?php } else { ?><a href="/admin/activity?months=60">Show 60 months</a><?php } ?>.</p>
</section>

<section class="card">
    <h2>Last <?= $days ?> days by kind</h2>
    <?php if ($total === 0) { ?>
        <p class="muted">Nothing published in the last <?= $days ?> days.</p>
    <?php } else { ?>
        <ul class="stats">
            <?php foreach ($kinds as $k) { ?>
                <li>
                    <div class="tile">
                        <span class="stat-count"><?= number_format($k['count']) ?></span>
                        <span class="stat-label"><?= $k['label'] ?></span>
                        <span class="stat-before muted small"><?= $total === 0 ? 0 : round($k['count'] / $total * 100) ?>% of the total</span>
                    </div>
                </li>
            <?php } ?>
        </ul>
        <p class="muted small"><?= number_format($total) ?> published webmentions in all. Held, hidden and deleted ones are left out here.</p>
    <?php } ?>
</section>

<section class="card">
    <h2>New accounts and sites</h2>
    <div class="table-wrap">
        <table class="data signups">
            <thead><tr><th>Month</th><th class="num">Accounts</th><th class="num">Sites</th><th class="num">Webmentions</th></tr></thead>
            <tbody>
                <?php foreach (array_reverse($signups, true) as $i => $s) { ?>
                    <?php if ($s['accounts'] === 0 && $s['sites'] === 0 && $webmentions[$i]['count'] === 0) { continue; } ?>
                    <tr>
                        <td class="nowrap"><?= $s['label'] ?></td>
                        <td class="num"><?= number_format($s['accounts']) ?></td>
                        <td class="num"><?= number_format($s['sites']) ?></td>
                        <td class="num"><?= number_format($webmentions[$i]['count']) ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <p class="muted small">Months with nothing at all are left out.</p>
</section>
