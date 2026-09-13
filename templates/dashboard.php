<?php
/**
 * @var list<array> $links
 */
?>
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
