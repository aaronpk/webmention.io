<?php
/**
 * @var list<array> $sites
 * @var string|null $saved  The id of the site just saved.
 * @var string      $csrf
 */
?>
<section class="card">
    <h2>Web hooks</h2>
    <p>Configure a web hook for a site and webmention.io will send it a POST request every time a webmention is received and verified.
        Invalid webmentions are not sent. Each site also has its own moderation setting and avatar option.</p>
</section>

<?php if ($sites === []) { ?>
    <section class="card">
        <p>You don't have any sites yet. <a href="/settings/sites">Add a site</a> and its settings will appear here.</p>
    </section>
<?php } else { ?>
    <?php foreach ($sites as $site) { ?>
        <section class="card site-settings">
            <h3><?= $site['domain'] ?><?php if ($saved === (string) $site['id']) { ?> <span class="badge">Saved</span><?php } ?></h3>
            <form action="/webhook/configure" method="post" class="settings-grid">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="site_id" value="<?= $site['id'] ?>">

                <div class="field">
                    <label for="url-<?= $site['id'] ?>">Callback URL</label>
                    <input type="url" id="url-<?= $site['id'] ?>" name="callback_url" value="<?= $site['callback_url'] ?>" placeholder="https://example.com/webmention/hook">
                </div>
                <div class="field">
                    <label for="secret-<?= $site['id'] ?>">Callback secret</label>
                    <input type="text" id="secret-<?= $site['id'] ?>" name="callback_secret" value="<?= $site['callback_secret'] ?>" maxlength="50" autocomplete="off" spellcheck="false">
                </div>

                <div class="field">
                    <label for="moderation-<?= $site['id'] ?>">Hold new webmentions for review</label>
                    <select id="moderation-<?= $site['id'] ?>" name="moderation">
                        <option value="off"<?= $site['moderation'] === 'off' ? ' selected' : '' ?>>Never: publish as soon as verified</option>
                        <option value="first"<?= $site['moderation'] === 'first' ? ' selected' : '' ?>>First-time senders: hold until one from that domain is approved</option>
                        <option value="all"<?= $site['moderation'] === 'all' ? ' selected' : '' ?>>Always: hold everything until approved</option>
                    </select>
                    <span class="muted small">Held webmentions wait on the dashboard, out of the API and this web hook, until approved.</span>
                </div>
                <div class="field">
                    <span class="label">Avatars</span>
                    <label class="checkbox">
                        <input type="checkbox" name="archive_avatars" value="1"<?= $site['archive_avatars'] ? ' checked' : '' ?>>
                        Archive avatars
                    </label>
                    <span class="muted small">Keeps a copy of each author's photo and returns that URL, so old webmentions don't end up with
                        broken images when someone changes their profile photo. Turn it off to use the original URLs.</span>
                </div>

                <div class="form-actions span">
                    <button type="submit">Save</button>
                </div>
            </form>
        </section>
    <?php } ?>
<?php } ?>

<section class="card spaced">
    <h2>Payload</h2>
    <p>The web hook payload looks like this:</p>
    <pre><code>{
  "secret": "1234abcd",
  "source": "http://rhiaro.co.uk/2015/11/1446953889",
  "target": "http://aaronparecki.com/notes/2015/11/07/4/indiewebcamp",
  "private": false,
  "post": {
    "type": "entry",
    "author": {
      "type": "card",
      "name": "Amy Guy",
      "photo": "https://avatars.webmention.io/rhiaro.co.uk/829d3f6e7083d7ee8bd7b20363da84d88ce5b4ce094f78fd1b27d8d3dc42560e.png",
      "url": "http://rhiaro.co.uk/about#me"
    },
    "url": "http://rhiaro.co.uk/2015/11/1446953889",
    "published": "2015-11-08T03:38:09+00:00",
    "wm-received": "2015-11-08T03:40:12Z",
    "wm-id": 900,
    "wm-source": "http://rhiaro.co.uk/2015/11/1446953889",
    "wm-target": "http://aaronparecki.com/notes/2015/11/07/4/indiewebcamp",
    "wm-protocol": "webmention",
    "name": "repost of http://aaronparecki.com/notes/2015/11/07/4/indiewebcamp",
    "repost-of": "http://aaronparecki.com/notes/2015/11/07/4/indiewebcamp",
    "wm-property": "repost-of",
    "wm-private": false
  }
}</code></pre>

    <p>The <code>wm-property</code> value (and the matching property in <code>post</code>) says what kind of post it is:</p>
    <ul>
        <li><code>in-reply-to</code></li>
        <li><code>like-of</code></li>
        <li><code>repost-of</code></li>
        <li><code>bookmark-of</code></li>
        <li><code>mention-of</code></li>
        <li><code>rsvp</code></li>
    </ul>

    <p>If a webmention is deleted, either because the post was deleted or you deleted it from the dashboard, the web hook receives:</p>
    <pre><code>{
  "secret": "1234abcd",
  "source": "http://rhiaro.co.uk/2015/11/1446953889",
  "target": "http://aaronparecki.com/notes/2015/11/07/4/indiewebcamp",
  "private": false,
  "deleted": true
}</code></pre>
</section>
