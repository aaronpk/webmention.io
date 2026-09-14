<?php
/**
 * @var list<array{id: int, domain: string, pages: int, mentions: int, verified: bool, checked_on: ?string, error: ?string}> $sites
 * @var string|null $checked      Result of a "Check now".
 * @var string      $endpoint
 * @var string|null $error
 * @var string|null $merged       Result of re-filing a moved page.
 * @var string|null $merge_error
 * @var string      $csrf
 */
// Messages are already escaped; URLs in them are set in <code> so they read as addresses, not missing links.
$withCode = static fn (string $text): string => (string) preg_replace('#https?://[^\s<>"()]+?(?=[.,:;]?(?:\s|$|\)))#', '<code>$0</code>', $text);
?>
<section class="card">
    <h2>Sites</h2>

    <?php if ($checked !== null) { ?>
        <p class="notice"><?= $checked ?></p>
    <?php } ?>

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
                        <?php $detail = !$site['verified'] && $site['checked_on'] !== null; ?>
                        <tr<?= $detail ? ' class="has-detail"' : '' ?>>
                            <td><?= $site['domain'] ?></td>
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
                                <?php if (!$site['verified']) { ?>
                                    <form action="/settings/sites/verify" method="post">
                                        <input type="hidden" name="site_id" value="<?= $site['id'] ?>">
                                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                        <button type="submit" class="secondary small">Check now</button>
                                    </form>
                                <?php } ?>
                            </td>
                        </tr>
                        <?php if ($detail) { ?>
                            <tr class="detail">
                                <td colspan="5" class="muted small">
                                    Checked <?= $site['checked_on'] ?><?= $site['error'] !== null ? ': ' . $withCode($site['error']) : '' ?>
                                </td>
                            </tr>
                        <?php } ?>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php if (array_filter($sites, static fn (array $s): bool => !$s['verified']) !== []) { ?>
            <p class="muted small">A site is verified when its home page, or a page that has received a mention, carries the tag below
                (as a <code>&lt;link&gt;</code>, an <code>&lt;a rel="webmention"&gt;</code> or a <code>Link</code> header) on that domain itself;
                a redirect to another site does not count.
                Sites added before this check existed are verified automatically once they are found to carry it.
                Until then an unverified site still receives its mentions, but if another account has verified the same domain,
                only that account's mentions appear in public API results for it.</p>
        <?php } ?>
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
        <a href="https://github.com/aaronpk/webmention.io#api">using the API</a>.</p>
</section>

<section class="card">
    <h2>Add a site</h2>
    <p>To add another domain, first put the tag above on that domain's home page (or a page that receives mentions), so it names
        this account's endpoint. That is how the site proves it is yours; without it, anyone could add
        your domain to their account. The page has to be served by that domain: a redirect to another site does not count.</p>
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
