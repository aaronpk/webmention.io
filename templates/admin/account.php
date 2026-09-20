<?php
/**
 * Everything the service knows about one account. See AccountReport::of().
 *
 * @var array       $account  id, username, domain, email, created_at, last_login, has_token, pingback, aperture
 * @var list<array>  $sites
 * @var int          $site_total
 * @var list<string> $site_rest  Names of the sites past the cap, not described here.
 * @var list<string> $blocks
 * @var int         $sources  blocked source URLs
 * @var list<array> $mutes
 * @var list<array> $mentions
 */
$adminTab = 'accounts';
$name = $account['domain'] ?? $account['username'] ?? ('Account ' . $account['id']);
?>
<section class="card">
    <h2><?= $name ?></h2>
    <?php require __DIR__ . '/_tabs.php'; ?>

    <p><a href="/admin/accounts">← All accounts</a></p>

    <dl class="facts">
        <dt>Account id</dt><dd><?= $account['id'] ?></dd>
        <dt>Domain</dt><dd><?= $account['domain'] ?? '—' ?></dd>
        <dt>Username</dt><dd><?= $account['username'] ?? '—' ?></dd>
        <dt>Email</dt><dd><?= $account['email'] ?? '—' ?></dd>
        <dt>Created</dt><dd><?= $account['created_at'] ?? '—' ?></dd>
        <dt>Last sign-in</dt><dd><?= $account['last_login'] ?? 'never' ?></dd>
        <dt>API token</dt><dd><?= $account['has_token'] ? 'set' : 'not set' ?></dd>
        <dt>Pingback</dt><dd><?= $account['pingback'] ? 'enabled' : 'off' ?></dd>
        <dt>Aperture</dt><dd><?= $account['aperture'] ? 'configured' : 'not configured' ?></dd>
    </dl>
    <p class="muted small">The API token is never shown here. This page is for working out why something is not working, not for acting as someone.</p>
</section>

<section class="card">
    <h2>Sites<?php if ($site_total > count($sites)) { ?> <span class="badge"><?= number_format($site_total) ?></span><?php } ?></h2>
    <?php if ($sites === []) { ?>
        <p class="muted">This account has no sites.</p>
    <?php } ?>
    <?php if ($site_rest !== []) { ?>
        <p class="notice">This account has <?= number_format($site_total) ?> sites. The first <?= count($sites) ?> are described below; the rest are listed at the end, because describing each one costs several queries.</p>
    <?php } ?>
    <?php foreach ($sites as $s) { ?>
        <h3><?= $s['domain'] ?? ('site ' . $s['id']) ?>
            <?php if ($s['archived_at'] !== null) { ?><span class="badge">archived</span><?php } ?>
            <?php if ($s['verified_at'] === null) { ?><span class="badge badge-error">unverified</span><?php } ?>
        </h3>
        <?php if ($s['error'] !== null && $s['error'] !== '') { ?>
            <p class="alert">Last verification check failed: <?= $s['error'] ?></p>
        <?php } ?>
        <?php foreach ($s['elsewhere'] as $other) { ?>
            <p class="alert"><?= $s['domain'] ?> is also verified on <a href="/admin/accounts/<?= $other['account_id'] ?>"><?= $other['name'] ?></a> (site <?= $other['site_id'] ?>).
                That usually means someone signed in with a domain they had already added to another account.</p>
        <?php } ?>
        <dl class="facts">
            <dt>Site id</dt><dd><?= $s['id'] ?></dd>
            <dt>Created</dt><dd><?= $s['created_at'] ?? '—' ?></dd>
            <dt>Verified</dt><dd><?= $s['verified_at'] ?? 'no' ?></dd>
            <dt>Last checked</dt><dd><?= $s['checked_at'] ?? 'never' ?></dd>
            <dt>Moderation</dt><dd><?= $s['moderation'] ?></dd>
            <dt>Web hook</dt><dd><?= $s['webhook'] ?? 'none' ?></dd>
            <dt>Pages</dt><dd><?= number_format($s['pages']) ?></dd>
            <dt>Webmentions</dt><dd><?= number_format($s['mentions']) ?></dd>
            <dt>Last mention</dt><dd><?= $s['last_mention'] ?? 'never' ?></dd>
        </dl>

        <?php if ($s['deliveries'] !== []) { ?>
            <p class="muted small">Web hook deliveries<?php if ($s['retries'] > 0) { ?>, with <?= $s['retries'] ?> waiting to be tried again<?php } ?>. Only the newest few are kept.</p>
            <div class="table-wrap">
                <table class="data deliveries">
                    <thead><tr><th>When</th><th>Kind</th><th class="num">Attempt</th><th class="num">Status</th><th>Error</th><th class="num">Time</th><th class="num">Mention</th></tr></thead>
                    <tbody>
                        <?php foreach ($s['deliveries'] as $d) { ?>
                            <tr>
                                <td class="nowrap"><?= $d['created'] ?></td>
                                <td><?= $d['kind'] ?></td>
                                <td class="num"><?= $d['attempt'] ?></td>
                                <td class="num"><?php if ($d['code'] === null) { ?><span class="badge badge-error">none</span><?php } elseif ($d['code'] >= 400) { ?><span class="badge badge-error"><?= $d['code'] ?></span><?php } else { ?><?= $d['code'] ?><?php } ?></td>
                                <td class="url"><?= $d['error'] ?? '' ?><?php if ($d['due'] !== null) { ?> <span class="muted small">retry due <?= gmdate('Y-m-d H:i:s', (int) $d['due']) ?></span><?php } ?></td>
                                <td class="num"><?= number_format($d['ms']) ?> ms</td>
                                <td class="num"><?php if ($d['link_id'] !== null) { ?><a href="/admin/lookup?q=<?= $d['link_id'] ?>"><?= $d['link_id'] ?></a><?php } ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } elseif ($s['webhook'] !== null) { ?>
            <p class="muted small">A web hook is configured but nothing has been delivered yet.</p>
        <?php } ?>
    <?php } ?>
    <?php if ($site_rest !== []) { ?>
        <h3>The other <?= number_format(count($site_rest)) ?> sites</h3>
        <p class="muted small"><?= implode(', ', $site_rest) ?></p>
    <?php } ?>
</section>

<section class="card">
    <h2>Blocks and mutes</h2>
    <p><?= count($blocks) ?> blocked domain<?= count($blocks) === 1 ? '' : 's' ?>, <?= number_format($sources) ?> blocked source URL<?= $sources === 1 ? '' : 's' ?>, <?= count($mutes) ?> mute rule<?= count($mutes) === 1 ? '' : 's' ?>.</p>
    <?php if ($blocks !== []) { ?>
        <p class="muted small">Blocked: <?= implode(', ', $blocks) ?></p>
    <?php } ?>
    <?php if ($mutes !== []) { ?>
        <div class="table-wrap">
            <table class="data mutes">
                <thead><tr><th>Kind</th><th>Pattern</th><th>Added</th></tr></thead>
                <tbody>
                    <?php foreach ($mutes as $m) { ?>
                        <tr><td><?= $m['kind'] ?></td><td class="url"><?= $m['pattern'] ?></td><td class="nowrap"><?= $m['created'] ?? '—' ?></td></tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>

<section class="card">
    <h2>Recent webmentions</h2>
    <?php if ($mentions === []) { ?>
        <p class="muted">This account has never received a webmention.</p>
    <?php } else { ?>
        <p class="muted">The newest <?= count($mentions) ?>, whatever state they are in.</p>
        <?php require __DIR__ . '/_mentions.php'; ?>
    <?php } ?>
</section>
