<?php
/**
 * @var string $token
 * @var string $html_url
 * @var string $atom_url
 * @var string $export_url
 * @var string|null $merge_error
 * @var string|null $merged
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

<section class="card">
    <h2>Export your data</h2>
    <p>Every published mention on your account, private ones included, as one <a href="/api#export">jf2</a> file: the same shape as
        <code>/api/mentions.jf2</code>, oldest first, one record per line. Use it for a backup or to move to another service.</p>
    <p class="alert"><strong>This downloads everything on your account in a single file.</strong>
        For an account with many years of mentions that can be hundreds of megabytes.
        An export can only be started once every five minutes; a second attempt inside that window is refused with a 429 response.</p>
    <p><a class="button" href="<?= $export_url ?>" download>Download export</a></p>
    <p class="muted small">The same file from the command line, or for one site only by adding <code>&amp;domain=example.com</code>:</p>
    <pre><code id="export-url"><?= $export_url ?></code></pre>
</section>

<section class="card">
    <h2>Moved to a new domain?</h2>
    <p>If you signed in before under another domain and its webmentions are on that older account, you can bring them here.
        The old domain has to point at this account first: either redirect its home page to one of this account's
        verified sites (<code>example.com</code> to <code>www.example.com</code>, say), or put this account's webmention tag on it.</p>
    <?php if ($merged !== null) { ?>
        <p class="notice"><?= $merged ?></p>
    <?php } ?>
    <?php if ($merge_error !== null) { ?>
        <p class="alert"><?= $merge_error ?></p>
    <?php } ?>
    <form action="/settings/merge-account" method="post" class="inline-field">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="text" name="old_domain" placeholder="old-domain.example" required aria-label="Old domain" autocapitalize="off" spellcheck="false">
        <button type="submit" class="secondary">Check the old domain</button>
    </form>
</section>
