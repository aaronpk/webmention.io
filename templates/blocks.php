<?php
/**
 * @var list<string> $domains  Domains blocked for the whole account.
 * @var list<array>  $mutes    Mute rules: id, kind, pattern, label.
 * @var string|null  $mute_notice
 * @var list<array>  $sources  This page of blocked URLs: id, site_id, domain, source, url (safe href or null), blocked_on.
 * @var int          $total    Blocked URLs on the account.
 * @var int          $matching Blocked URLs matching the filter (equals $total with no filter).
 * @var string       $q        The filter, or "".
 * @var int          $page     Zero-based.
 * @var int          $pages
 * @var string       $csrf
 */
$pageQuery = static fn (int $p): string => '/settings/blocks?' . http_build_query(array_filter(['q' => html_entity_decode($q, ENT_QUOTES | ENT_HTML5), 'page' => $p > 0 ? $p : null], static fn ($v): bool => $v !== null && $v !== ''));
?>
<section class="card">
    <h2>Blocked domains</h2>
    <p class="muted">Webmentions from these domains are refused on every site on your account.</p>

    <?php if ($domains === []) { ?>
        <p class="muted">You haven't blocked any domains.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="data">
                <tbody>
                    <?php foreach ($domains as $domain) { ?>
                        <tr>
                            <td><?= $domain ?></td>
                            <td class="actions">
                                <form action="/unblock" method="post">
                                    <input type="hidden" name="domain" value="<?= $domain ?>">
                                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                    <button type="submit" class="secondary">Unblock</button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <p class="muted small">Unblocking a domain does not restore webmentions that were deleted when it was blocked.</p>
    <?php } ?>
</section>

<section class="card">
    <h2>Muted sources and authors</h2>
    <p class="muted">Muting hides webmentions without deleting them: existing ones disappear from the API and your web hook stops hearing
        about new ones, and unmuting brings them all back. Mute a <em>source</em> to cover the pages that mention you, or an <em>author</em>
        to cover everything by someone, wherever it was relayed from (a bridged social account, say). A domain covers its subdomains;
        a URL prefix such as <code>https://social.example/@someone/</code> covers exactly those URLs.</p>

    <?php if ($mute_notice !== null) { ?>
        <p class="notice"><?= $mute_notice ?></p>
    <?php } ?>

    <?php if ($mutes !== []) { ?>
        <div class="table-wrap">
            <table class="data">
                <tbody>
                    <?php foreach ($mutes as $rule) { ?>
                        <tr>
                            <td><?= $rule['label'] ?></td>
                            <td class="actions">
                                <form action="/unmute-rule" method="post">
                                    <input type="hidden" name="id" value="<?= $rule['id'] ?>">
                                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                    <button type="submit" class="secondary small">Unmute</button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>

    <form action="/mute" method="post" class="inline-field">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <select name="kind" aria-label="What to mute">
            <option value="source">Source</option>
            <option value="author">Author</option>
        </select>
        <input type="text" name="pattern" placeholder="example.com or https://example.com/user/" required aria-label="Domain or URL prefix" autocapitalize="off" spellcheck="false">
        <button type="submit" class="secondary">Mute</button>
    </form>
</section>

<section class="card">
    <h2>Blocked URLs</h2>
    <p class="muted">When you delete a webmention from the dashboard, its source URL is blocked for that site, so the same
        webmention is refused if it is sent again. Unblocking a URL lets it be received again; it does not restore the deleted webmention.</p>

    <?php if ($total === 0) { ?>
        <p class="muted">You haven't blocked any URLs.</p>
    <?php } else { ?>
        <form action="/settings/blocks" method="get" class="inline-field">
            <input type="search" name="q" value="<?= $q ?>" placeholder="Filter by part of the URL" aria-label="Filter blocked URLs" autocapitalize="off" spellcheck="false">
            <button type="submit" class="secondary">Filter</button>
            <?php if ($q !== '') { ?>
                <a class="button secondary" href="/settings/blocks">Clear</a>
            <?php } ?>
        </form>

        <p class="muted small filter-count">
            <?php if ($q !== '') { ?>
                <?= number_format($matching) ?> of <?= number_format($total) ?> blocked URL<?= $total === 1 ? '' : 's' ?> match.
            <?php } else { ?>
                <?= number_format($total) ?> blocked URL<?= $total === 1 ? '' : 's' ?>.
            <?php } ?>
        </p>

        <?php if ($sources !== []) { ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr><th>URL</th><th>Site</th><th>Blocked</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sources as $row) { ?>
                            <tr>
                                <td class="url">
                                    <?php if ($row['url'] !== null) { ?>
                                        <a href="<?= $row['url'] ?>" rel="nofollow noopener"><?= $row['source'] ?></a>
                                    <?php } else { ?>
                                        <?= $row['source'] ?>
                                    <?php } ?>
                                </td>
                                <td class="nowrap"><?= $row['domain'] ?></td>
                                <td class="nowrap muted"><?= $row['blocked_on'] ?? '' ?></td>
                                <td class="actions">
                                    <form action="/unblock-source" method="post">
                                        <input type="hidden" name="site_id" value="<?= $row['site_id'] ?>">
                                        <input type="hidden" name="source" value="<?= $row['source'] ?>">
                                        <input type="hidden" name="q" value="<?= $q ?>">
                                        <input type="hidden" name="page" value="<?= $page ?>">
                                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                        <button type="submit" class="secondary small">Unblock</button>
                                    </form>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pages > 1) { ?>
                <nav class="pager" aria-label="Blocked URL pages">
                    <?php if ($page > 0) { ?>
                        <a href="<?= $pageQuery($page - 1) ?>">&larr; Newer</a>
                    <?php } else { ?>
                        <span></span>
                    <?php } ?>
                    <span class="muted">Page <?= $page + 1 ?> of <?= $pages ?></span>
                    <?php if ($page + 1 < $pages) { ?>
                        <a href="<?= $pageQuery($page + 1) ?>">Older &rarr;</a>
                    <?php } else { ?>
                        <span></span>
                    <?php } ?>
                </nav>
            <?php } ?>
        <?php } ?>
    <?php } ?>
</section>

<section class="card">
    <h2>Delete or block by URL</h2>
    <p class="muted">Enter the source URL of a webmention. The next step lets you delete it, delete everything from that URL, or block its whole domain.</p>
    <form action="/delete" method="get" class="inline-field">
        <input type="url" name="source" placeholder="https://spam.example/post" required aria-label="Source URL">
        <button type="submit" class="secondary">Preview delete</button>
    </form>
</section>
