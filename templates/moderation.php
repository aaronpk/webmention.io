<?php
/**
 * Every mention awaiting review, paged.
 *
 * @var list<array> $links   See DashboardController::row().
 * @var int         $total
 * @var int         $page    Zero-based.
 * @var int         $pages
 * @var string      $csrf
 */
$show_delete = false;
?>
<section class="card">
    <h2>Awaiting review</h2>
    <p class="muted"><?= number_format($total) ?> webmention<?= $total === 1 ? '' : 's' ?> waiting. They are not shown in the API or sent to
        your web hook until approved. Approving one from a domain also lets future mentions from that domain through when a site's
        policy is "hold first-time senders".</p>

    <?php if ($links === []) { ?>
        <p class="muted">Nothing to review. <a href="/dashboard">Back to the dashboard</a>.</p>
    <?php } else { ?>
        <ul class="mention-list">
            <?php foreach ($links as $link) { require __DIR__ . '/_row.php'; } ?>
        </ul>

        <?php if ($pages > 1) { ?>
            <nav class="pager" aria-label="Review pages">
                <?php if ($page > 0) { ?><a href="/moderation?page=<?= $page - 1 ?>">&larr; Newer</a><?php } else { ?><span></span><?php } ?>
                <span class="muted">Page <?= $page + 1 ?> of <?= $pages ?></span>
                <?php if ($page + 1 < $pages) { ?><a href="/moderation?page=<?= $page + 1 ?>">Older &rarr;</a><?php } else { ?><span></span><?php } ?>
            </nav>
        <?php } ?>
    <?php } ?>
</section>
