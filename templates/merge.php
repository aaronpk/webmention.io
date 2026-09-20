<?php
/**
 * Confirm merging an old account into the current one.
 *
 * @var string       $old_domain  The other account's domain.
 * @var string       $old_name    Its name (username), which may differ from the domain.
 * @var list<string> $sites       The other account's sites.
 * @var int          $mentions    How many webmentions move.
 * @var string       $csrf
 */
?>
<section class="card narrow">
    <h2>Merge the account <?= $old_name ?> into this one?</h2>
    <p><code><?= $old_domain ?></code> now points at this account, so the account holding it can be merged in.</p>
    <dl class="facts">
        <dt>Account</dt><dd><?= $old_name ?><?= $old_name !== $old_domain ? ' (' . $old_domain . ')' : '' ?></dd>
        <dt>Sites</dt><dd><?= $sites === [] ? 'none' : implode(', ', $sites) ?></dd>
        <dt>Webmentions</dt><dd><?= number_format($mentions) ?></dd>
    </dl>
    <p>Everything on that account moves here: the sites listed, their webmentions, and its blocks and mutes. A site this account
        already has is combined with the incoming one. The other account is then deleted: signing in as <code><?= $old_domain ?></code>
        afterwards lands on this account only if that domain's IndieAuth identifies you the same way, and its API token stops working.
        This cannot be undone.</p>
    <form action="/settings/merge-account/confirm" method="post" class="form-actions">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="old_domain" value="<?= $old_domain ?>">
        <button type="submit" class="danger">Merge and delete the other account</button>
        <a class="button secondary" href="/settings/sites#bring">Cancel</a>
    </form>
</section>
