<?php
/**
 * Source domains of the last $days days, busiest first.
 *
 * @var list<array> $sources  domain, total, pending, deleted, last_seen, blocked, muted (rule text or null), browse_url, review_url, block_url.
 * @var int         $days
 * @var int         $limit
 * @var string|null $notice
 * @var string      $csrf
 */
?>
<?php if ($notice !== null) { ?>
    <p class="notice"><?= $notice ?></p>
<?php } ?>

<section class="card">
    <h2>Sources</h2>
    <p class="muted">Where your webmentions came from in the last <?= $days ?> days, busiest first<?php if (count($sources) >= $limit) { ?> (the top <?= $limit ?>)<?php } ?>.
        Hidden and deleted ones are counted too, since they were received. Counts refresh every few minutes.</p>

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
                        <th>Last seen</th>
                        <th></th>
                        <th class="actions"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sources as $s) { ?>
                        <tr>
                            <td class="url"><a href="<?= $s['browse_url'] ?>"><?= $s['domain'] ?></a></td>
                            <td class="num"><?= number_format($s['total']) ?></td>
                            <td class="num"><?php if ($s['pending'] > 0) { ?><a href="<?= $s['review_url'] ?>"><?= number_format($s['pending']) ?></a><?php } else { ?><span class="muted">0</span><?php } ?></td>
                            <td class="num"><?= $s['deleted'] > 0 ? number_format($s['deleted']) : '<span class="muted">0</span>' ?></td>
                            <td class="nowrap"><?= $s['last_seen'] ?></td>
                            <td class="nowrap">
                                <?php if ($s['blocked']) { ?><span class="badge badge-error">Blocked</span><?php } ?>
                                <?php if ($s['muted'] !== null) { ?><span class="badge" title="<?= $s['muted'] ?>">Muted</span><?php } ?>
                            </td>
                            <td class="actions nowrap">
                                <?php if (!$s['blocked'] && $s['muted'] === null) { ?>
                                    <form action="/mute" method="post" class="inline">
                                        <input type="hidden" name="kind" value="source">
                                        <input type="hidden" name="pattern" value="<?= $s['domain'] ?>">
                                        <input type="hidden" name="back" value="/sources">
                                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                        <button type="submit" class="secondary small" title="Keep receiving from <?= $s['domain'] ?> but hide it all">Mute</button>
                                    </form>
                                <?php } ?>
                                <?php if (!$s['blocked']) { ?>
                                    <a class="button secondary small" href="<?= $s['block_url'] ?>" title="Delete everything from <?= $s['domain'] ?> and refuse it from now on">Block…</a>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>
