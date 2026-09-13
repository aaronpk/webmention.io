<?php
/**
 * @var list<array{domain: string, pages: int, mentions: int}> $sites
 * @var string      $endpoint
 * @var string|null $error
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
                    <tr><th>Domain</th><th>Pages</th><th>Webmentions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($sites as $site) { ?>
                        <tr>
                            <td><?= $site['domain'] ?></td>
                            <td class="num"><?= number_format($site['pages']) ?></td>
                            <td class="num"><?= number_format($site['mentions']) ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
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
    <p>To add another domain, first put the tag above on that domain's home page, so it names
        this account's endpoint. That is how the site proves it is yours; without it, anyone could add
        your domain to their account.</p>
    <?php if ($error !== null) { ?>
        <p class="alert"><?= $error ?></p>
    <?php } ?>
    <form action="/settings/sites/new" method="post" class="inline-field">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="text" name="domain" placeholder="example.com" required aria-label="Domain" autocapitalize="off" spellcheck="false">
        <button type="submit">Add site</button>
    </form>
</section>
