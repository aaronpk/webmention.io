<?php
/**
 * @var string|null $notice
 * @var string|null $error
 * @var string      $csrf
 */
?>
<section class="card narrow">
    <p class="muted small"><a href="/settings/sites">&larr; Sites</a></p>
    <h2>Moved a page?</h2>
    <?php if ($notice !== null) { ?>
        <p class="notice"><?= $notice ?></p>
    <?php } ?>
    <?php if ($error !== null) { ?>
        <p class="alert"><?= $error ?></p>
    <?php } ?>
    <p>Mentions are filed under a page's canonical URL, following your site's redirects and <code>rel="canonical"</code>.
        If you changed a URL after mentions arrived, enter the old URL: if it now redirects to the new one, its mentions are
        re-filed there and the old URL keeps working as an alias in the API.</p>
    <form action="/settings/sites/merge" method="post" class="inline-field">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="url" name="old_url" placeholder="https://example.com/old-url" required aria-label="Old URL" autofocus>
        <button type="submit">Re-file mentions</button>
    </form>
</section>
