<?php
/**
 * @var list<array{id: int, domain: string, pages: int, mentions: int, last_mention: ?string, verified: bool}> $sites  Live sites.
 * @var list<array{id: int, domain: string, archived_on: ?string, deleting: bool}> $archived
 * @var string|null $notice       A message from the last action.
 * @var array{domain: string, account: ?string}|null $conflict  The sign-in domain is verified on another account.
 * @var string      $endpoint
 * @var string|null $error
 * @var string|null $merged       Result of re-filing a moved page.
 * @var string|null $merge_error
 * @var string      $csrf
 */
?>
<section class="card">
    <h2>Sites</h2>

    <?php if ($notice !== null) { ?>
        <p class="notice"><?= $notice ?></p>
    <?php } ?>

    <?php if ($conflict !== null) { $who = $conflict['account'] ?? 'another account'; ?>
        <div class="alert">
            <p><strong><?= $conflict['domain'] ?> is already set up on the account <?= $who ?></strong>, and its pages advertise that account's
                webmention endpoint, so webmentions for it go there. It is listed below but not verified here.</p>
            <p>If that account is also yours, sign out, sign in as <?= $who ?>, and use "Moved to a new domain?" on its Settings page
                to bring this account into it. If <?= $conflict['domain'] ?> has moved here, change its
                <code>rel="webmention"</code> link to this account's endpoint and check the site again.</p>
        </div>
    <?php } ?>

    <?php if ($sites === [] && $archived === []) { ?>
        <p>Add a site, then add the tag below to any pages you want to receive webmentions for.</p>
    <?php } elseif ($sites === []) { ?>
        <p>Every site on this account is archived. Add a site below, or unarchive one from its page.</p>
    <?php } else { ?>
        <form action="/settings/sites/archive" method="post">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr><th class="check"></th><th>Domain</th><th>Pages</th><th>Webmentions</th><th>Last webmention</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sites as $site) { ?>
                            <tr>
                                <td class="check"><input type="checkbox" name="site_id[]" value="<?= $site['id'] ?>" aria-label="Select <?= $site['domain'] ?>"></td>
                                <td><a href="/settings/sites/<?= $site['id'] ?>"><?= $site['domain'] ?></a></td>
                                <td class="num"><?= number_format($site['pages']) ?></td>
                                <td class="num"><?= number_format($site['mentions']) ?></td>
                                <td class="nowrap">
                                    <?php if ($site['last_mention'] !== null) { ?>
                                        <?= $site['last_mention'] ?>
                                    <?php } else { ?>
                                        <span class="muted">never</span>
                                    <?php } ?>
                                </td>
                                <td>
                                    <?php if ($site['verified']) { ?>
                                        <span class="badge">Verified</span>
                                    <?php } else { ?>
                                        <span class="badge badge-error">Not verified</span>
                                    <?php } ?>
                                </td>
                                <td class="actions">
                                    <a class="button secondary small" href="/settings/sites/<?= $site['id'] ?>">Settings</a>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <div class="form-actions">
                <button type="submit" class="secondary small">Archive selected</button>
                <span class="muted small">Archived sites stop accepting webmentions but keep the ones they have. Unarchive or delete a site from its page.</span>
            </div>
            <p class="muted small">You can export your site's webmentions after it is archived.</p>
        </form>
        <p class="muted small">Each site's settings page has its verification status, web hook, moderation and avatar options.</p>
    <?php } ?>
</section>

<?php if ($archived !== []) { ?>
<section class="card" id="archived">
    <h2>Archived sites</h2>
    <p class="muted small">These no longer accept webmentions. The webmentions they received are still in the API and your export.</p>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr><th>Domain</th><th>Archived</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($archived as $site) { ?>
                    <tr>
                        <td>
                            <?php if ($site['deleting']) { ?>
                                <?= $site['domain'] ?>
                            <?php } else { ?>
                                <a href="/settings/sites/<?= $site['id'] ?>"><?= $site['domain'] ?></a>
                            <?php } ?>
                        </td>
                        <td class="nowrap"><?= $site['archived_on'] ?></td>
                        <td class="actions">
                            <?php if ($site['deleting']) { ?>
                                <span class="muted small">Deleting…</span>
                            <?php } else { ?>
                                <a class="button secondary small" href="/settings/sites/<?= $site['id'] ?>">Settings</a>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</section>
<?php } ?>

<section class="card">
    <h2>Setup</h2>
    <p>Add this tag to your website to accept webmentions:</p>
    <div class="inline-field">
        <pre class="grow"><code id="setup-code">&lt;link rel="webmention" href="<?= $endpoint ?>" /&gt;</code></pre>
        <button type="button" class="secondary" data-copy="setup-code">Copy</button>
    </div>
    <p class="muted">Webmentions for any site on your account are accepted at this endpoint, and can be queried
        <a href="/api">using the API</a>.</p>
</section>

<section class="card">
    <h2>Add a site</h2>
    <p>To add another domain, first put the tag above on that domain's home page (or a page that receives mentions), so it names
        this account's endpoint. That is how the site proves it is yours; without it, anyone could add
        your domain to their account. The page has to be served by that domain (or its <code>www.</code> twin): a redirect to another site does not count.</p>
    <?php if ($error !== null) { ?>
        <p class="alert"><?= $error ?></p>
    <?php } ?>
    <form action="/settings/sites/new" method="post" class="inline-field">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="text" name="domain" placeholder="example.com" required aria-label="Domain" autocapitalize="off" spellcheck="false">
        <button type="submit">Add site</button>
    </form>
</section>

<section class="card">
    <h2>Moved a page?</h2>
    <p class="muted">Mentions are filed under a page's canonical URL, following your site's redirects and <code>rel="canonical"</code>.
        If you changed a URL after mentions arrived, enter the old URL: if it now redirects to the new one, its mentions are
        re-filed there and the old URL keeps working as an alias in the API.</p>
    <?php if ($merged !== null) { ?>
        <p class="notice"><?= $merged ?></p>
    <?php } ?>
    <?php if ($merge_error !== null) { ?>
        <p class="alert"><?= $merge_error ?></p>
    <?php } ?>
    <form action="/settings/sites/merge" method="post" class="inline-field">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="url" name="old_url" placeholder="https://example.com/old-url" required aria-label="Old URL">
        <button type="submit" class="secondary">Re-file mentions</button>
    </form>
</section>
