<?php
/**
 * One site: verification, web hook, moderation and avatar settings.
 *
 * @var array{id: int, domain: string, pages: int, mentions: int, verified: bool, verified_on: ?string, checked_on: ?string, error: ?string,
 *            callback_url: string, callback_secret: string, archive_avatars: bool, moderation: string} $site
 * @var string      $endpoint
 * @var bool        $saved    The settings form was just saved.
 * @var string|null $checked  Result of a "Check now".
 * @var string      $csrf
 */
// Messages are already escaped; URLs in them are set in <code> so they read as addresses, not missing links.
$withCode = static fn (string $text): string => (string) preg_replace('#https?://[^\s<>"()]+?(?=[.,:;]?(?:\s|$|\)))#', '<code>$0</code>', $text);
?>
<p class="muted small"><a href="/settings/sites">Sites</a> › <?= $site['domain'] ?></p>

<section class="card">
    <h2><?= $site['domain'] ?>
        <?php if ($site['verified']) { ?>
            <span class="badge">Verified</span>
        <?php } else { ?>
            <span class="badge badge-error">Not verified</span>
        <?php } ?>
    </h2>
    <dl class="facts">
        <dt>Pages</dt><dd><?= number_format($site['pages']) ?></dd>
        <dt>Webmentions</dt><dd><?= number_format($site['mentions']) ?></dd>
    </dl>
</section>

<section class="card">
    <h2>Verification</h2>

    <?php if ($checked !== null) { ?>
        <p class="notice"><?= $withCode($checked) ?></p>
    <?php } ?>

    <?php if ($site['verified']) { ?>
        <p>Your webmention tag was found on this site<?= $site['verified_on'] !== null ? ' on ' . $site['verified_on'] : '' ?>, so it is known to be yours.</p>
    <?php } else { ?>
        <p>Your webmention link was not yet found on this site. It still receives its mentions, but if another account has verified
            the same domain, only that account's mentions appear in public API results for it.</p>
        <?php if ($site['checked_on'] !== null) { ?>
            <p class="muted small">Last checked <?= $site['checked_on'] ?><?= $site['error'] !== null ? ': ' . $withCode($site['error']) : '' ?></p>
        <?php } ?>
        <p>To verify it, put this tag on the site's home page, or on a page that has received a mention, and check again:</p>
        <pre><code>&lt;link rel="webmention" href="<?= $endpoint ?>" /&gt;</code></pre>
        <form action="/settings/sites/verify" method="post" class="form-actions">
            <input type="hidden" name="site_id" value="<?= $site['id'] ?>">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <button type="submit" class="secondary">Check now</button>
        </form>
        <p class="muted small">The tag counts as a <code>&lt;link&gt;</code>, an <code>&lt;a rel="webmention"&gt;</code> or a <code>Link</code> header,
            served by that domain itself; a redirect to another site does not count. Sites added before this check existed are verified
            automatically once they are found to carry it.</p>
    <?php } ?>
</section>

<section class="card">
    <h2>Settings<?php if ($saved) { ?> <span class="badge">Saved</span><?php } ?></h2>
    <form action="/webhook/configure" method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="site_id" value="<?= $site['id'] ?>">

        <h3>Web hook</h3>
        <p class="muted small">Every verified webmention for this site is POSTed to the callback URL as JSON, with the secret in the body
            and a signature header. The payload is described in the <a href="/api#webhooks">API documentation</a>.</p>
        <div class="settings-grid">
            <div class="field">
                <label for="callback_url">Callback URL</label>
                <input type="url" id="callback_url" name="callback_url" value="<?= $site['callback_url'] ?>" placeholder="https://example.com/webmention/hook">
            </div>
            <div class="field">
                <label for="callback_secret">Callback secret</label>
                <input type="text" id="callback_secret" name="callback_secret" value="<?= $site['callback_secret'] ?>" maxlength="50" autocomplete="off" spellcheck="false">
            </div>
        </div>

        <h3>Moderation</h3>
        <div class="field">
            <label for="moderation">Hold new webmentions for review</label>
            <select id="moderation" name="moderation">
                <option value="off"<?= $site['moderation'] === 'off' ? ' selected' : '' ?>>Never: publish as soon as verified</option>
                <option value="first"<?= $site['moderation'] === 'first' ? ' selected' : '' ?>>First-time senders: hold until one from that domain is approved</option>
                <option value="all"<?= $site['moderation'] === 'all' ? ' selected' : '' ?>>Always: hold everything until approved</option>
            </select>
            <span class="muted small">Held webmentions wait on the <a href="/moderation">review page</a>, out of the API and the web hook, until approved.
                Muting and blocking are under <a href="/settings/blocks">Blocklists</a>.</span>
        </div>

        <h3>Avatars</h3>
        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="archive_avatars" value="1"<?= $site['archive_avatars'] ? ' checked' : '' ?>>
                Archive avatars
            </label>
            <span class="muted small">Keeps a copy of each author's photo and returns that URL, so old webmentions don't end up with
                broken images when someone changes their profile photo. Turn it off to use the original URLs.</span>
        </div>

        <div class="form-actions">
            <button type="submit">Save</button>
        </div>
    </form>
</section>
