<?php
/**
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
