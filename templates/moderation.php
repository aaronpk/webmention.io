<?php
/**
 * Every mention awaiting review, paged.
 *
 * @var list<array> $links   See MentionRow::row().
 * @var int         $total
 * @var int         $page    Zero-based.
 * @var int         $pages
 * @var string      $csrf
 */
$show_delete = false;
$bulk        = true;
$back        = '/moderation' . ($page > 0 ? "?page=$page" : '');
$pageQuery   = static fn (int $p): string => '/moderation' . ($p > 0 ? "?page=$p" : '');
?>
<section class="card">
    <h2>Awaiting review</h2>
    <p class="muted"><?= number_format($total) ?> webmention<?= $total === 1 ? '' : 's' ?> waiting. They are not shown in the API or sent to
        your web hook until approved. Approving one from a domain also lets future mentions from that domain through when a site's
        policy is "hold first-time senders".</p>

    <?php if ($links === []) { ?>
        <p class="muted">Nothing to review. <a href="/dashboard">Back to the dashboard</a>.</p>
    <?php } else { ?>
        <?php require __DIR__ . '/_bulk.php'; ?>

        <ul class="mention-list selectable">
            <?php foreach ($links as $link) { require __DIR__ . '/_row.php'; } ?>
        </ul>

        <?php $pagerLabel = 'Review pages'; require __DIR__ . '/_pager.php'; ?>
    <?php } ?>
</section>
