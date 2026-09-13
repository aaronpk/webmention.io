<?php
/**
 * @var string $token
 * @var string $html_url
 * @var string $atom_url
 * @var string $csrf
 */
?>
<section class="card">
    <h2>Mentions feed</h2>
    <p>Every mention received on your account, as a Microformats feed you can follow in a reader like
        <a href="https://aperture.p3k.io">Aperture</a> (try its "Notifications" channel), or as Atom.</p>
    <pre><code id="html-feed"><?= $html_url ?></code></pre>
    <pre><code id="atom-feed"><?= $atom_url ?></code></pre>
</section>

<section class="card">
    <h2>API key</h2>
    <p>You need this token to list mentions for a whole domain rather than a single URL.
        It can only read webmentions sent to your sites; it can't change anything on your account.</p>
    <div class="inline-field">
        <pre class="grow"><code id="api-token"><?= $token ?></code></pre>
        <button type="button" class="secondary" data-copy="api-token">Copy</button>
    </div>

    <form action="/settings/change_token" method="post" class="form-actions" data-confirm="Generate a new token? Anything using the current one will stop working.">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <button type="submit" class="secondary">Generate new token</button>
    </form>
</section>
