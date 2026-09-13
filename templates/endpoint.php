<?php
/**
 * @var string $username
 */
?>
<section class="card narrow">
    <p class="badge">Webmention endpoint</p>
    <h1><?= $username ?></h1>
    <p>This is the webmention endpoint for <strong><?= $username ?></strong>.
        <a href="https://webmention.net/">Webmention</a> is a simple way to notify any URL when you link to it on your site.</p>

    <form class="stack" action="/<?= rawurlencode(html_entity_decode($username, ENT_QUOTES | ENT_HTML5)) ?>/webmention" method="post">
        <div class="field">
            <label for="source">Source URL</label>
            <input type="url" name="source" id="source" required placeholder="The page sending this webmention (probably yours)">
        </div>
        <div class="field">
            <label for="target">Target URL</label>
            <input type="url" name="target" id="target" required placeholder="The page that should receive this webmention">
        </div>
        <div class="form-actions">
            <button type="submit">Send Webmention</button>
        </div>
    </form>
</section>
