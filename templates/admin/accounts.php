<?php
/**
 * Finding an account to look at.
 *
 * @var string      $query
 * @var list<array> $accounts id, username, domain, email, sites, created_at, last_login
 * @var int         $limit
 */
$adminTab = 'accounts';
?>
<section class="card">
    <h2>Accounts</h2>
    <?php require __DIR__ . '/_tabs.php'; ?>

    <form action="/admin/accounts" method="get" class="inline-field">
        <label class="field grow"><span class="label">Domain, username, email or id</span>
            <input type="search" name="q" value="<?= $query ?>" placeholder="example.com" autocomplete="off">
        </label>
        <button type="submit">Search</button>
    </form>

    <?php if ($accounts === []) { ?>
        <p class="muted">No account matches <code><?= $query ?></code>.</p>
    <?php } else { ?>
        <p class="filter-count"><?= $query === '' ? 'The ' . count($accounts) . ' most recently active accounts.' : count($accounts) . ' match' . (count($accounts) === 1 ? '' : 'es') . (count($accounts) >= $limit ? ' (the first ' . $limit . ')' : '') . '.' ?></p>
        <div class="table-wrap">
            <table class="data accounts">
                <thead><tr><th class="num">ID</th><th>Domain</th><th>Username</th><th>Email</th><th class="num">Sites</th><th>Created</th><th>Last sign-in</th></tr></thead>
                <tbody>
                    <?php foreach ($accounts as $a) { ?>
                        <tr>
                            <td class="num"><a href="/admin/accounts/<?= $a['id'] ?>"><?= $a['id'] ?></a></td>
                            <td class="url"><a href="/admin/accounts/<?= $a['id'] ?>"><?= $a['domain'] ?? '—' ?></a></td>
                            <td class="url"><?= $a['username'] ?? '—' ?></td>
                            <td class="url"><?= $a['email'] ?? '—' ?></td>
                            <td class="num"><?= number_format($a['sites']) ?></td>
                            <td class="nowrap"><?= $a['created_at'] ?? '—' ?></td>
                            <td class="nowrap"><?= $a['last_login'] ?? 'never' ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>
