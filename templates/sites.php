<?php
/**
 * @var list<array{id: int, domain: string, pages: int, mentions: int, verified: bool}> $sites
 * @var string      $endpoint
 * @var string|null $error
 * @var string|null $merged       Result of re-filing a moved page.
 * @var string|null $merge_error
 * @var string      $csrf
 */
?>
<section class="card">
    <h2>Sites</h2>

    <?php if ($sites === []) { ?>
        <p>Add a site, then add the tag below to any pages you want to receive webmentions for.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><th>Domain</th><th>Pages</th><th>Webmentions</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($sites as $site) { ?>
                        <tr>
                            <td><a href="/settings/sites/<?= $site['id'] ?>"><?= $site['domain'] ?></a></td>
                            <td class="num"><?= number_format($site['pages']) ?></td>
                            <td class="num"><?= number_format($site['mentions']) ?></td>
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
        <p class="muted small">Each site's settings page has its verification status, web hook, moderation and avatar options.</p>
    <?php } ?>
</section>

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
