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

<section class="doc" id="display-mention-counter">
    <h2><a href="#display-mention-counter">Display a mention counter</a></h2>
    <p>You can use the API from JavaScript to display a mention count for one or more URLs.
        The API sends <code>Access-Control-Allow-Origin: *</code>, so it works from a browser as well as a server.</p>
    <pre><code>fetch("<?= $base_url ?>/api/count?target=https://example.com/page/100")
    .then(response =&gt; response.json())
    .then(data =&gt; console.log(data));</code></pre>
    <p>This returns the total number of mentions of the URL, as well as the count by type.</p>
    <pre><code>{
  "count": 6,
  "type": {
    "bookmark": 1,
    "mention": 2,
    "rsvp-maybe": 1,
    "rsvp-no": 1,
    "rsvp-yes": 1
  }
}</code></pre>
</section>

<section class="doc" id="show-all-mentions">
    <h2><a href="#show-all-mentions">Show all mentions</a></h2>
    <p>You can also use the API to list every mention of a URL.</p>
    <pre><code>fetch("<?= $base_url ?>/api/mentions.jf2?target=https://example.com/page/100")
    .then(response =&gt; response.json())
    .then(data =&gt; console.log(data));</code></pre>
    <p>The response looks like this:</p>
    <pre><code>{
  "type": "feed",
  "name": "Webmentions",
  "children": [
    {
      "type": "entry",
      "author": {
        "type": "card",
        "name": "Tantek Çelik",
        "url": "http://tantek.com/",
        "photo": "http://tantek.com/logo.jpg"
      },
      "url": "http://tantek.com/2013/112/t2/milestone-show-indieweb-comments-h-entry-pingback",
      "published": "2013-04-22T15:03:00-07:00",
      "wm-received": "2013-04-25T17:09:33Z",
      "wm-id": 900,
      "wm-source": "http://tantek.com/2013/112/t2/milestone-show-indieweb-comments-h-entry-pingback",
      "wm-target": "https://indieweb.org/",
      "content": {
        "html": "Another milestone: &lt;a href=\"https://twitter.com/eschnou\"&gt;@eschnou&lt;/a&gt; automatically shows #indieweb comments…",
        "text": "Another milestone: @eschnou automatically shows #indieweb comments…"
      },
      "mention-of": "https://indieweb.org/",
      "wm-property": "mention-of",
      "wm-private": false
    }
  ]
}</code></pre>
</section>

<section class="doc" id="more">
    <h2><a href="#more">More API docs</a></h2>
    <p>Filtering, sorting, paging, Atom feeds and more are described in <a href="https://github.com/aaronpk/webmention.io#api">the project's README</a>.</p>
</section>
