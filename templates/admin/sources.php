<?php
/**
 * Which domains have been sending the whole service webmentions.
 *
 * @var list<array> $sources domain, total, pending, deleted, accounts, last_seen
 * @var string      $sort
 * @var int         $days
 * @var int         $limit
 */
$adminTab = 'sources';
?>
<section class="card">
    <h2>Sources</h2>
    <?php require __DIR__ . '/_tabs.php'; ?>

    <p class="muted">Every domain that sent the service a webmention in the last <?= $days ?> days<?php if (count($sources) >= $limit) { ?> (the top <?= $limit ?>)<?php } ?>.
        Held and deleted ones are counted: they were received, and they cost the same fetches. Counts refresh every few minutes.</p>

    <p class="muted small">Ordered by
        <?php if ($sort === 'total') { ?><strong>how many were sent</strong><?php } else { ?><a href="/admin/sources">how many were sent</a><?php } ?>
        ·
        <?php if ($sort === 'deleted') { ?><strong>how many were deleted</strong><?php } else { ?><a href="/admin/sources?sort=deleted">how many were deleted</a><?php } ?>.
    </p>

    <?php if ($sources === []) { ?>
        <p class="muted">Nothing has arrived in the last <?= $days ?> days.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="data sources">
                <thead>
                    <tr>
                        <th>Domain</th>
                        <th class="num">Webmentions</th>
                        <th class="num">Awaiting review</th>
                        <th class="num">Deleted</th>
                        <th class="num">Accounts reached</th>
                        <th>Last seen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sources as $s) { ?>
                        <tr>
                            <td class="url"><a href="/admin/lookup?q=<?= $s['domain'] ?>"><?= $s['domain'] ?></a></td>
                            <td class="num"><?= number_format($s['total']) ?></td>
                            <td class="num"><?= $s['pending'] > 0 ? number_format($s['pending']) : '<span class="muted">0</span>' ?></td>
                            <td class="num"><?php if ($s['deleted'] > 0 && $s['total'] > 0 && $s['deleted'] / $s['total'] >= 0.5) { ?><span class="badge badge-error"><?= number_format($s['deleted']) ?></span><?php } elseif ($s['deleted'] > 0) { ?><?= number_format($s['deleted']) ?><?php } else { ?><span class="muted">0</span><?php } ?></td>
                            <td class="num"><?= number_format($s['accounts']) ?></td>
                            <td class="nowrap"><?= $s['last_seen'] ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <p class="muted small">A domain whose mentions are mostly deleted, across many accounts, is the shape abuse takes here; its deleted count is marked. Blocking is still each account's own decision, under Settings › Blocklists.</p>
    <?php } ?>
</section>
