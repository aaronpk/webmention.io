<?php
/**
 * @var string      $endpoint  This account's webmention endpoint.
 * @var string|null $error
 * @var string      $csrf
 */
?>
<section class="card narrow">
    <p class="muted small"><a href="/settings/sites">&larr; Sites</a></p>
    <h2>Add a site</h2>
    <?php if ($error !== null) { ?>
        <p class="alert"><?= $error ?></p>
    <?php } ?>
    <p>First put this tag on the domain's home page (or a page that receives mentions), so it names this account's endpoint.
        That is how the site proves it is yours; without it, anyone could add your domain to their account. The page has to be
        served by that domain (or its <code>www.</code> twin): a redirect to another site does not count.</p>
    <div class="inline-field">
        <pre class="grow"><code id="setup-code">&lt;link rel="webmention" href="<?= $endpoint ?>" /&gt;</code></pre>
        <button type="button" class="secondary" data-copy="setup-code">Copy</button>
    </div>
    <form action="/settings/sites/new" method="post" class="inline-field">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="text" name="domain" placeholder="example.com" required aria-label="Domain" autocapitalize="off" spellcheck="false" autofocus>
        <button type="submit">Add site</button>
    </form>
</section>
