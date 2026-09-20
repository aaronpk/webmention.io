<?php
/**
 * @var string      $endpoint  This account's webmention endpoint.
 * @var string|null $error
 * @var string      $csrf
 */
?>
<section class="card narrow">
    <p class="muted small"><a href="/settings/sites">&larr; Sites</a></p>
    <h2>Bring in a site from another account</h2>
    <?php if ($error !== null) { ?>
        <p class="alert"><?= $error ?></p>
    <?php } ?>
    <p>If a site of yours is on another account, because you once signed in with a different domain or an early account was named
        differently, you can bring that whole account here: its sites, webmentions, blocks and mutes.</p>
    <p>Enter the site's domain, or the other account's name. The domain has to point at this account first: put this account's
        webmention tag on its home page (both the HTML tag and any <code>Link</code> header), or redirect the home page to one of this
        account's verified sites.</p>
    <div class="inline-field">
        <pre class="grow"><code id="setup-code">&lt;link rel="webmention" href="<?= $endpoint ?>" /&gt;</code></pre>
        <button type="button" class="secondary" data-copy="setup-code">Copy</button>
    </div>
    <form action="/settings/merge-account" method="post" class="inline-field">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="text" name="site" placeholder="example.com" required aria-label="Domain or account name" autocapitalize="off" spellcheck="false" autofocus>
        <button type="submit">Check</button>
    </form>
    <p class="muted small">Nothing moves until you confirm on the next page, which lists what would come over.</p>
</section>
