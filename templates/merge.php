<?php
/**
 * Confirm merging an old account into the current one.
 *
 * @var string       $old_domain
 * @var list<string> $sites     The old account's sites.
 * @var int          $mentions  How many webmentions move.
 * @var string       $csrf
 */
?>
<section class="card narrow">
    <h2>Merge <?= $old_domain ?> into this account?</h2>
    <p><code><?= $old_domain ?></code> now points at this account, so its old account can be merged in.</p>
    <dl class="facts">
        <dt>Sites</dt><dd><?= $sites === [] ? 'none' : implode(', ', $sites) ?></dd>
        <dt>Webmentions</dt><dd><?= number_format($mentions) ?></dd>
    </dl>
    <p>Its sites, webmentions, blocks and mutes move here. A site this account already has is combined with the incoming one.
        The old account is then deleted: signing in as <code><?= $old_domain ?></code> afterwards lands on this account only if
        that domain's IndieAuth identifies you the same way, and its old API token stops working. This cannot be undone.</p>
    <form action="/settings/merge-account/confirm" method="post" class="form-actions">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="old_domain" value="<?= $old_domain ?>">
        <button type="submit" class="danger">Merge and delete the old account</button>
        <a class="button secondary" href="/settings">Cancel</a>
    </form>
</section>
