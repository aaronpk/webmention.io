<?php
/**
 * Confirm deleting a site and everything it received.
 *
 * @var array{id: int, domain: string, pages: int, mentions: int} $site
 * @var string      $export_url     This site's webmentions as a jf2 download.
 * @var bool        $sign_in_domain The account signs in with this domain.
 * @var string|null $error
 * @var string      $csrf
 */
?>
<p class="muted small"><a href="/settings/sites">Sites</a> › <a href="/settings/sites/<?= $site['id'] ?>"><?= $site['domain'] ?></a> › Delete</p>

<section class="card narrow danger">
    <h2>Delete <?= $site['domain'] ?>?</h2>
    <dl class="facts">
        <dt>Pages</dt><dd><?= number_format($site['pages']) ?></dd>
        <dt>Webmentions</dt><dd><?= number_format($site['mentions']) ?></dd>
    </dl>
    <p>This removes the site and every webmention it received, including held and deleted ones, along with its blocked sources
        and web hook history. It stops accepting webmentions immediately. <strong>This cannot be undone.</strong></p>
    <p>To keep a copy, <a href="<?= $export_url ?>" download>download this site's webmentions</a> first,
        or archive the site instead, which keeps them.</p>
    <?php if ($sign_in_domain) { ?>
        <p class="muted small">This is the domain you sign in with. If it is your only site, it is added back, empty,
            the next time you open the Sites page, so your account can keep receiving webmentions.</p>
    <?php } ?>
    <?php if ($error !== null) { ?>
        <p class="alert"><?= $error ?></p>
    <?php } ?>
    <form action="/settings/sites/delete" method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="site_id" value="<?= $site['id'] ?>">
        <div class="field">
            <label for="confirm_domain">Type <code><?= $site['domain'] ?></code> to confirm</label>
            <input type="text" id="confirm_domain" name="confirm_domain" required autocomplete="off" autocapitalize="off" spellcheck="false">
        </div>
        <div class="form-actions">
            <button type="submit" class="danger">Delete this site permanently</button>
            <a class="button secondary" href="/settings/sites/<?= $site['id'] ?>">Cancel</a>
        </div>
    </form>
</section>
