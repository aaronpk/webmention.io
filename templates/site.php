<?php
/**
 * One site: verification, web hook, moderation and avatar settings.
 *
 * @var array{id: int, domain: string, pages: int, mentions: int, verified: bool, verified_on: ?string, checked_on: ?string, error: ?string,
 *            callback_url: string, callback_secret: string, archive_avatars: bool, moderation: string} $site
 * @var array{months: list<array{month: string, label: string, count: int}>, max: int, total: int} $activity  Received per month, oldest first.
 * @var string      $endpoint
 * @var bool        $saved        The settings form was just saved.
 * @var string|null $checked      Result of a "Check now".
 * @var list<array{id: int, when: string, kind: string, ok: bool, result: string, duration: int, source: string, target: string,
 *                 request: string, response: ?string}> $deliveries  Newest first; empty when no callback URL is set.
 * @var bool        $has_mentions The site has a published mention that can be sent as a test.
 * @var bool        $sent         A delivery was just sent by hand.
 * @var string|null $resend_error Why one could not be.
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

    <?php
    // Received per month as a row of bars: 4 units per month, 1 unit gap,
    // 20 units tall. The drawing is stretched to the full width of the card
    // (preserveAspectRatio="none"), so bars and gaps widen with the window.
    // Zero months keep a stub so the timeline reads evenly; the current
    // month is drawn muted because it is not over yet.
    $bars  = count($activity['months']);
    $width = $bars * 5 - 1;
    ?>
    <figure class="sparkline">
        <svg viewBox="0 0 <?= $width ?> 20" preserveAspectRatio="none" role="img" aria-label="Webmentions received per month">
            <?php foreach ($activity['months'] as $i => $m) { ?>
                <?php $h = $m['count'] === 0 ? 0.5 : max(1, round($m['count'] / $activity['max'] * 20, 1)); ?>
                <rect x="<?= $i * 5 ?>" y="<?= 20 - $h ?>" width="4" height="<?= $h ?>"<?= $i === $bars - 1 ? ' class="current"' : '' ?>><title><?= $m['label'] ?>: <?= number_format($m['count']) ?> webmention<?= $m['count'] === 1 ? '' : 's' ?></title></rect>
            <?php } ?>
        </svg>
        <figcaption class="muted small">
            <span><?= $activity['months'][0]['label'] ?></span>
            <span><?= number_format($activity['total']) ?> received in the last <?= ($bars - 1) % 12 === 0 ? (($bars - 1) / 12) . ' years' : ($bars - 1) . ' months' ?></span>
            <span><?= $activity['months'][$bars - 1]['label'] ?></span>
        </figcaption>
    </figure>
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

<?php if ($site['callback_url'] !== '') { ?>
<section class="card" id="deliveries">
    <h2>Web hook deliveries</h2>

    <?php if ($sent) { ?>
        <p class="notice">Sent. The result is the newest delivery below.</p>
    <?php } ?>
    <?php if ($resend_error !== null) { ?>
        <p class="alert"><?= $resend_error ?></p>
    <?php } ?>

    <?php if ($deliveries === []) { ?>
        <p>Nothing has been sent to <code><?= $site['callback_url'] ?></code> yet. A delivery goes out each time a webmention for this site
            verifies (and is approved, if you hold them for review).</p>
    <?php } else { ?>
        <?php $last = $deliveries[0]; ?>
        <?php if ($last['ok']) { ?>
            <p>The last delivery, <?= $last['when'] ?>, was accepted (<?= $last['result'] ?>).</p>
        <?php } else { ?>
            <p class="alert"><strong>The last delivery, <?= $last['when'] ?>, failed: <?= $last['result'] ?>.</strong>
                Your endpoint has to answer with a 2xx status within 20 seconds, be reachable from the public internet
                (not a private or local address), and if it uses https, present a valid certificate. Nothing is retried on its own;
                once it is fixed, re-send a delivery below to check.</p>
        <?php } ?>
    <?php } ?>

    <form action="/webhook/resend" method="post" class="form-actions">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="site_id" value="<?= $site['id'] ?>">
        <button type="submit" class="secondary"<?= $has_mentions ? '' : ' disabled' ?>>Send the latest webmention now</button>
        <?php if (!$has_mentions) { ?>
            <span class="muted small">This site has no published webmention to send yet.</span>
        <?php } else { ?>
            <span class="muted small">Sends this site's newest webmention to the callback URL again, as a test.</span>
        <?php } ?>
    </form>

    <?php if ($deliveries !== []) { ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th>When</th><th>Kind</th><th>Result</th><th>Time</th><th>Webmention</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($deliveries as $d) { ?>
                        <tr class="has-detail">
                            <td class="nowrap"><?= $d['when'] ?></td>
                            <td><?= $d['kind'] ?></td>
                            <td><span class="badge<?= $d['ok'] ? '' : ' badge-error' ?>"><?= $d['result'] ?></span></td>
                            <td class="num nowrap"><?= number_format($d['duration']) ?> ms</td>
                            <td class="url small"><code><?= $d['source'] ?></code> → <code><?= $d['target'] ?></code></td>
                            <td class="actions">
                                <form action="/webhook/resend" method="post" class="inline">
                                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                    <input type="hidden" name="site_id" value="<?= $site['id'] ?>">
                                    <input type="hidden" name="delivery_id" value="<?= $d['id'] ?>">
                                    <button type="submit" class="secondary small">Re-send</button>
                                </form>
                            </td>
                        </tr>
                        <tr class="detail">
                            <td colspan="6">
                                <details>
                                    <summary>Request and response</summary>
                                    <p class="muted small">Sent to <code><?= $site['callback_url'] ?></code>:</p>
                                    <pre><code><?= $d['request'] ?></code></pre>
                                    <?php if ($d['response'] !== null) { ?>
                                        <p class="muted small">The endpoint answered:</p>
                                        <pre><code><?= $d['response'] ?></code></pre>
                                    <?php } else { ?>
                                        <p class="muted small">The endpoint sent no body back.</p>
                                    <?php } ?>
                                </details>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <p class="muted small">The newest 50 deliveries are kept. Re-sending uses the site's current callback URL and secret.</p>
    <?php } ?>
</section>
<?php } ?>
