<?php
/**
 * One box for whatever a support email contains. See Lookup::find().
 *
 * @var string      $query
 * @var string      $kind     empty, nothing, or found
 * @var list<array> $sections
 */
$adminTab = 'lookup';
?>
<section class="card">
    <h2>Lookup</h2>
    <?php require __DIR__ . '/_tabs.php'; ?>

    <form action="/admin/lookup" method="get" class="inline-field">
        <label class="field grow"><span class="label">A source URL, a target URL, a domain, an account name, a status receipt or an id</span>
            <input type="search" name="q" value="<?= $query ?>" placeholder="https://example.com/post" autocomplete="off" autofocus>
        </label>
        <button type="submit">Look up</button>
    </form>

    <?php if ($kind === 'empty') { ?>
        <p class="muted">Paste whatever you were given. A URL is tried both as something sent and as something received; a bare number is tried as a webmention, an account and a site id.</p>
    <?php } elseif ($kind === 'nothing') { ?>
        <p class="muted">Nothing in the database matches <code><?= $query ?></code>.</p>
    <?php } ?>
</section>

<?php foreach ($sections as $s) { ?>
    <section class="card">
        <h2><?= $s['title'] ?></h2>
        <?php if (($s['note'] ?? null) !== null) { ?><p class="muted"><?= $s['note'] ?></p><?php } ?>

        <?php if ($s['kind'] === 'receipt') { ?>
            <pre><code><?= $s['json'] ?></code></pre>

        <?php } elseif ($s['kind'] === 'mentions') { ?>
            <?php if (($s['account_id'] ?? null) !== null) { ?>
                <p><a href="/admin/accounts/<?= $s['account_id'] ?>">Everything about this account →</a></p>
            <?php } ?>
            <?php $mentions = $s['mentions']; require __DIR__ . '/_mentions.php'; ?>

        <?php } elseif ($s['kind'] === 'accounts') { ?>
            <div class="table-wrap">
                <table class="data accounts">
                    <thead><tr><th class="num">ID</th><th>Domain</th><th>Username</th></tr></thead>
                    <tbody>
                        <?php foreach ($s['accounts'] as $a) { ?>
                            <tr>
                                <td class="num"><a href="/admin/accounts/<?= $a['id'] ?>"><?= $a['id'] ?></a></td>
                                <td class="url"><a href="/admin/accounts/<?= $a['id'] ?>"><?= $a['domain'] ?? '—' ?></a></td>
                                <td class="url"><?= $a['username'] ?? '—' ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

        <?php } elseif ($s['kind'] === 'sites') { ?>
            <div class="table-wrap">
                <table class="data sites">
                    <thead><tr><th class="num">ID</th><th>Domain</th><th>Account</th><th>Verified</th><th>Last checked</th><th>Problem</th></tr></thead>
                    <tbody>
                        <?php foreach ($s['sites'] as $site) { ?>
                            <tr>
                                <td class="num"><?= $site['id'] ?></td>
                                <td class="url"><?= $site['domain'] ?? '—' ?><?php if ($site['archived_at'] !== null) { ?> <span class="badge">archived</span><?php } ?></td>
                                <td class="url"><a href="/admin/accounts/<?= $site['account_id'] ?>"><?= $site['account'] ?></a></td>
                                <td class="nowrap"><?= $site['verified_at'] ?? 'no' ?></td>
                                <td class="nowrap"><?= $site['checked_at'] ?? 'never' ?></td>
                                <td class="url"><?php if ($site['error'] !== null && $site['error'] !== '') { ?><span class="badge badge-error"><?= $site['error'] ?></span><?php } ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

        <?php } elseif ($s['kind'] === 'sending') { ?>
            <dl class="facts">
                <dt>Webmentions sent</dt><dd><?= number_format($s['sending']['total']) ?></dd>
                <dt>Awaiting review</dt><dd><?= number_format($s['sending']['pending']) ?></dd>
                <dt>Deleted by their recipients</dt><dd><?= number_format($s['sending']['deleted']) ?></dd>
                <dt>Accounts reached</dt><dd><?= number_format($s['sending']['accounts']) ?></dd>
                <dt>Last seen</dt><dd><?= $s['sending']['last_seen'] ?></dd>
            </dl>
        <?php } ?>
    </section>
<?php } ?>
