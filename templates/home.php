<?php
/**
 * @var string      $base_url
 * @var bool        $signed_in
 * @var string|null $error
 * @var string      $me     A website address to prefill.
 */
?>
<section class="hero">
    <img src="/img/webmention-logo-380.png" alt="" width="120" height="120" class="hero-logo">
    <div>
        <h1>Webmention.io</h1>
        <p class="lede">A hosted service that receives <a href="https://webmention.net/">webmentions</a> for any web page.</p>
        <p class="muted">Read more about the project on the <a href="https://indieweb.org/webmention.io">IndieWeb wiki</a>.</p>

        <?php if ($error !== null) { ?>
            <p class="alert"><?= $error ?></p>
        <?php } ?>

        <?php if ($signed_in) { ?>
            <p><a class="button" href="/dashboard">Go to your dashboard</a></p>
        <?php } else { ?>
            <form class="sign-in" action="/auth/start" method="post">
                <label for="me">Sign in with your website</label>
                <div class="inline-field">
                    <input type="url" id="me" name="me" value="<?= $me ?>" placeholder="https://example.com" required autocomplete="url">
                    <button type="submit">Sign in</button>
                </div>
            </form>
        <?php } ?>
    </div>
</section>

<section class="doc" id="use-it">
    <h2><a href="#use-it">Use it on your site</a></h2>
    <p>Once you have signed in, add the following tag to your HTML, replacing "username" with your username:</p>
    <pre><code>&lt;link rel="webmention" href="<?= $base_url ?>/username/webmention" /&gt;</code></pre>
    <p>The service will begin collecting webmentions on your behalf.</p>
    <p>Your username is most likely your domain. For instance, if your website is <code>https://aaronparecki.com/</code>, your username is <code>aaronparecki.com</code>.</p>
</section>

<section class="doc" id="show-mentions">
    <h2><a href="#show-mentions">Show mentions on your pages</a></h2>
    <p>Drop three lines into a page and its likes, reposts, replies and mentions appear, with no dependencies:</p>
    <pre><code>&lt;div data-webmention-target="https://example.com/post/"&gt;&lt;/div&gt;
&lt;link rel="stylesheet" href="<?= $base_url ?>/assets/webmention-render.css"&gt;
&lt;script src="<?= $base_url ?>/js/webmention-render.js" defer&gt;&lt;/script&gt;</code></pre>
    <p>The <a href="/api">API documentation</a> covers the script's options and shows it running, along with the JSON API for
        listing and counting mentions, Atom and h-feed feeds, web hooks, example data for development, and a full export of your account.</p>
</section>
