<?php
/**
 * @var array       $overview       See AccountOverview::recent(): days, total, before, kinds[type, label, count, before].
 * @var list<array> $pending        Mentions awaiting review (first page).
 * @var int         $pending_total
 * @var list<array> $links          Recent published mentions.
 * @var string|null $notice
 * @var string      $csrf
 */
$back = '/dashboard';
?>
<?php if ($notice !== null) { ?>
    <p class="notice"><?= $notice ?></p>
<?php } ?>

<section class="card overview">
    <h2>Last <?= $overview['days'] ?> days</h2>
    <?php if ($overview['total'] === 0 && $overview['before'] === 0 && $pending_total === 0) { ?>
        <p class="muted">No webmentions in the last <?= $overview['days'] * 2 ?> days. <a href="/mentions">Browse everything you have received</a>.</p>
    <?php } else { ?>
        <ul class="stats">
            <?php foreach ($overview['kinds'] as $k) { ?>
                <li>
                    <a href="/mentions?type=<?= $k['type'] ?>">
                        <span class="stat-count"><?= number_format($k['count']) ?></span>
                        <span class="stat-label"><?= $k['label'] ?></span>
                        <span class="stat-before muted small">vs <?= number_format($k['before']) ?> before</span>
                    </a>
                </li>
            <?php } ?>
            <?php if ($pending_total > 0) { ?>
                <li class="attention">
                    <a href="/mentions?status=pending">
                        <span class="stat-count"><?= number_format($pending_total) ?></span>
                        <span class="stat-label">Awaiting review</span>
                        <span class="stat-before muted small">across all time</span>
                    </a>
                </li>
            <?php } ?>
        </ul>
        <p class="muted small"><?= number_format($overview['total']) ?> in all, against <?= number_format($overview['before']) ?> in the <?= $overview['days'] ?> days before. Counts refresh every few minutes.</p>
    <?php } ?>
</section>

<?php if ($pending !== []) { ?>
    <section class="card review">
        <h2>Awaiting review <span class="badge"><?= number_format($pending_total) ?></span></h2>
        <p class="muted">Held by a site's moderation setting. Nothing here appears in the API or reaches your web hook until you approve it.</p>
        <ul class="mention-list">
            <?php foreach ($pending as $link) { require __DIR__ . '/_row.php'; } ?>
        </ul>
        <?php if ($pending_total > count($pending)) { ?>
            <p><a href="/moderation">See all <?= number_format($pending_total) ?> waiting →</a></p>
        <?php } ?>
    </section>
<?php } ?>

<section class="card">
    <h2>Recent webmentions</h2>

    <?php if ($links === []) { ?>
        <p>There are no webmentions yet! <a href="/settings/sites">Add a site</a> first, then wait for someone to send you a webmention.</p>
    <?php } else { ?>
        <ul class="mention-list">
            <?php foreach ($links as $link) { require __DIR__ . '/_row.php'; } ?>
        </ul>
    <?php } ?>
</section>

<section class="card">
    <h2>Delete a webmention</h2>
    <p class="muted">Paste the source URL of a webmention you want to delete. The next step lets you delete everything from that URL, or block its whole domain.</p>

    <form action="/delete" method="get" class="inline-field">
        <input type="url" name="source" placeholder="https://spam.example/post" required aria-label="Source URL">
        <button type="submit" class="secondary">Preview delete</button>
    </form>
</section>
