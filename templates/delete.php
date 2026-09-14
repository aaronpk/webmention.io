<?php
/**
 * @var array|null  $link         The single webmention picked from a list, if any.
 * @var string|null $source       The source URL, when any undeleted webmentions came from it.
 * @var list<array> $links        Undeleted webmentions from that source.
 * @var string|null $domain
 * @var int         $domain_count
 * @var string      $csrf
 */
$show_delete = false;
?>
<?php if ($link === null && $links === []) { ?>
    <section class="card narrow">
        <h1>Nothing to delete</h1>
        <p>No webmentions were found from that source.</p>
        <p><a href="/dashboard">Back to the dashboard</a></p>
    </section>
<?php } else { ?>

    <?php if ($link !== null) { ?>
        <section class="card">
            <h2>Delete this webmention</h2>
            <p class="muted small">Its source URL is also blocked for this site, so the same webmention is refused if it is sent again.
                You can unblock it under <a href="/settings/blocks">Blocklists</a>.</p>
            <ul class="mention-list">
                <?php (function () use ($link, $show_delete) { require __DIR__ . '/_row.php'; })(); ?>
            </ul>
            <form action="/delete" method="post" class="form-actions">
                <input type="hidden" name="id" value="<?= $link['id'] ?>">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <button type="submit" class="danger">Delete</button>
            </form>
        </section>
    <?php } ?>

    <?php if ($links !== []) { ?>
        <section class="card">
            <h2>Delete all webmentions from this source URL</h2>
            <p class="muted small">The source URL is also blocked on every site on your account, so webmentions from it are refused if sent again.
                You can unblock it under <a href="/settings/blocks">Blocklists</a>.</p>
            <ul class="mention-list">
                <?php foreach ($links as $row) { (function () use ($row, $show_delete) { $link = $row; require __DIR__ . '/_row.php'; })(); } ?>
            </ul>
            <form action="/delete" method="post" class="form-actions">
                <input type="hidden" name="source" value="<?= $source ?>">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <button type="submit" class="danger">Delete all</button>
            </form>
        </section>
    <?php } ?>

    <?php if ($domain !== null) { ?>
        <section class="card danger">
            <h2>Block this domain</h2>
            <p><code><?= $domain ?></code></p>
            <p>You have received <?= $domain_count ?> webmention<?= $domain_count === 1 ? '' : 's' ?> from this domain.
                Blocking it deletes all of them and refuses any future webmentions from it to your account.</p>
            <form action="/delete" method="post" class="form-actions" data-confirm="Block <?= $domain ?> and delete every webmention from it?">
                <input type="hidden" name="domain" value="<?= $domain ?>">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <button type="submit" class="danger">Block and delete</button>
            </form>
        </section>
    <?php } ?>

    <p class="muted small">Deleted webmentions no longer appear in the API, and future webmentions from the same source URL are ignored.
        If a site has a web hook configured, it is sent a deletion notice so you can remove your own copy.</p>
<?php } ?>
