<?php
/**
 * @var string|null $error
 * @var string      $csrf
 */
?>
<section class="card narrow">
    <p class="muted small"><a href="/settings/blocks">&larr; Blocklists</a></p>
    <h2>Mute a source or author</h2>
    <?php if ($error !== null) { ?>
        <p class="alert"><?= $error ?></p>
    <?php } ?>
    <p>Muting hides webmentions without deleting them: existing ones disappear from the API and your web hook stops hearing
        about new ones, and unmuting brings them all back. Mute a <em>source</em> to cover the pages that mention you, or an <em>author</em>
        to cover everything by someone, wherever it was relayed from (a bridged social account, say). A domain covers its subdomains;
        a URL prefix such as <code>https://social.example/@someone/</code> covers exactly those URLs.</p>
    <form action="/mute" method="post" class="inline-field">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <select name="kind" aria-label="What to mute">
            <option value="source">Source</option>
            <option value="author">Author</option>
        </select>
        <input type="text" name="pattern" placeholder="example.com or https://example.com/user/" required aria-label="Domain or URL prefix" autocapitalize="off" spellcheck="false" autofocus>
        <button type="submit">Mute</button>
    </form>
</section>
