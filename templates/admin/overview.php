<?php
/**
 * Is the service healthy, and what has been arriving. See ServiceOverview::now().
 *
 * @var int         $queue
 * @var int         $queue_max
 * @var int         $retries
 * @var int         $purges
 * @var list<array> $workers      name, at, pid, jobs, alive, ago
 * @var int         $stale_after
 * @var list<array> $received     label, total, published, pending, hidden, deleted
 * @var array       $accounts     total, week, month
 * @var array       $sites        total, verified, archived, week, month
 * @var array       $verification unverified, recheck, days, failing
 * @var array       $webhooks     total, failed
 * @var list<array> $tables       name, rows, data_mb, index_mb
 * @var string      $counted_at
 */
$adminTab = 'overview';
$ago = static function (int $seconds): string {
    if ($seconds < 90) {
        return $seconds . 's ago';
    }
    if ($seconds < 5400) {
        return round($seconds / 60) . ' min ago';
    }
    if ($seconds < 172800) {
        return round($seconds / 3600) . ' hours ago';
    }

    return round($seconds / 86400) . ' days ago';
};
?>
<section class="card">
    <h2>Admin</h2>
    <?php require __DIR__ . '/_tabs.php'; ?>

    <h3>Right now</h3>
    <ul class="stats">
        <li<?= $queue >= $queue_max / 2 ? ' class="attention"' : '' ?>>
            <div class="tile">
                <span class="stat-count"><?= number_format($queue) ?></span>
                <span class="stat-label">In the queue</span>
                <span class="stat-before muted small">refused above <?= number_format($queue_max) ?></span>
            </div>
        </li>
        <li<?= $retries > 0 ? ' class="attention"' : '' ?>>
            <div class="tile">
                <span class="stat-count"><?= number_format($retries) ?></span>
                <span class="stat-label">Web hook retries</span>
                <span class="stat-before muted small"><?= number_format($webhooks['failed']) ?> of <?= number_format($webhooks['total']) ?> failed today</span>
            </div>
        </li>
        <li>
            <div class="tile">
                <span class="stat-count"><?= number_format($purges) ?></span>
                <span class="stat-label">Sites being removed</span>
                <span class="stat-before muted small">batched through the workers</span>
            </div>
        </li>
        <li<?= $verification['failing'] > 0 ? ' class="attention"' : '' ?>>
            <div class="tile">
                <span class="stat-count"><?= number_format($verification['failing']) ?></span>
                <span class="stat-label">Failing verification</span>
                <span class="stat-before muted small"><?= number_format($verification['unverified']) ?> unverified, <?= number_format($verification['recheck']) ?> due a recheck</span>
            </div>
        </li>
    </ul>

    <h3>Workers</h3>
    <?php if ($workers === []) { ?>
        <p class="muted">No worker has reported in. Either none is running, or none has run since this was deployed.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="data workers">
                <thead><tr><th>Worker</th><th>State</th><th>Last heard from</th><th class="num">PID</th><th class="num">Jobs this run</th></tr></thead>
                <tbody>
                    <?php foreach ($workers as $w) { ?>
                        <tr>
                            <td><?= $w['name'] ?></td>
                            <td><?php if ($w['alive']) { ?><span class="badge">running</span><?php } else { ?><span class="badge badge-error">not running</span><?php } ?></td>
                            <td class="nowrap"><?= $w['at'] === 0 ? 'never' : $ago($w['ago']) ?></td>
                            <td class="num"><?= $w['pid'] ?></td>
                            <td class="num"><?= number_format($w['jobs']) ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <p class="muted small">A worker that has not reported within <?= $stale_after ?> seconds is treated as stopped. Names come from <code>bin/worker --name=</code>.</p>
    <?php } ?>
</section>

<section class="card">
    <h2>Webmentions received</h2>
    <div class="table-wrap">
        <table class="data received">
            <thead><tr><th>Window</th><th class="num">Received</th><th class="num">Published</th><th class="num">Awaiting review</th><th class="num">Hidden</th><th class="num">Deleted</th></tr></thead>
            <tbody>
                <?php foreach ($received as $r) { ?>
                    <tr>
                        <td><?= $r['label'] ?></td>
                        <td class="num"><?= number_format($r['total']) ?></td>
                        <td class="num"><?= number_format($r['published']) ?></td>
                        <td class="num"><?= number_format($r['pending']) ?></td>
                        <td class="num"><?= number_format($r['hidden']) ?></td>
                        <td class="num"><?= number_format($r['deleted']) ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <p class="muted small">Counted at <?= $counted_at ?>, and again at most once a minute. <a href="/admin/activity">See it by month →</a></p>
</section>

<section class="card">
    <h2>Accounts and sites</h2>
    <ul class="stats">
        <li><a href="/admin/accounts"><span class="stat-count"><?= number_format($accounts['total']) ?></span><span class="stat-label">Accounts</span><span class="stat-before muted small">+<?= number_format($accounts['month']) ?> in 30 days</span></a></li>
        <li><a href="/admin/accounts"><span class="stat-count"><?= number_format($sites['total']) ?></span><span class="stat-label">Sites</span><span class="stat-before muted small">+<?= number_format($sites['month']) ?> in 30 days</span></a></li>
        <li><a href="/admin/accounts"><span class="stat-count"><?= number_format($sites['verified']) ?></span><span class="stat-label">Verified sites</span><span class="stat-before muted small"><?= number_format($sites['archived']) ?> archived</span></a></li>
        <li><div class="tile"><span class="stat-count"><?= number_format($verification['recheck']) ?></span><span class="stat-label">Due a recheck</span><span class="stat-before muted small">unchecked for <?= $verification['days'] ?> days</span></div></li>
    </ul>
</section>

<section class="card">
    <h2>Tables</h2>
    <p class="muted">Row counts are InnoDB's estimates. Counting the links table exactly means reading every row of it, which is not worth doing to draw a page.</p>
    <div class="table-wrap">
        <table class="data tables">
            <thead><tr><th>Table</th><th class="num">Rows (approx.)</th><th class="num">Data</th><th class="num">Indexes</th></tr></thead>
            <tbody>
                <?php foreach ($tables as $t) { ?>
                    <tr>
                        <td><?= $t['name'] ?></td>
                        <td class="num"><?= number_format($t['rows']) ?></td>
                        <td class="num"><?= number_format($t['data_mb']) ?> MB</td>
                        <td class="num"><?= number_format($t['index_mb']) ?> MB</td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</section>
