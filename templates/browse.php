<?php
/**
 * The mention browser.
 *
 * @var list<array>  $links   See MentionRow::row().
 * @var int          $total
 * @var int          $page    Zero-based.
 * @var int          $pages
 * @var string       $status  One of LinkSearch::STATUSES.
 * @var int|null     $site    Selected site id.
 * @var list<array>  $sites   id, domain, archived.
 * @var string       $type    Selected type filter, or "".
 * @var list<string> $types
 * @var string       $domain  Source domain filter, or "".
 * @var array        $query   The active filters, for building links (escaped values).
 * @var string       $back    Where row actions return to.
 * @var string|null  $notice
 * @var string       $csrf
 */
$decode      = static fn (array $q): array => array_map(static fn ($v): string => html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5), $q);
$build       = static fn (array $q): string => '/mentions' . ($q === [] ? '' : '?' . http_build_query($decode($q)));
$href        = static fn (array $q): string => htmlspecialchars($build($q), ENT_QUOTES | ENT_HTML5);
$pageQuery   = static fn (int $p): string => $build($p > 0 ? [...$query, 'page' => $p] : $query);
$show_delete = true;
$bulk        = $status === 'pending';
$statuses    = ['published' => 'Published', 'pending' => 'Awaiting review', 'hidden' => 'Hidden', 'deleted' => 'Deleted'];
$typeLabels  = ['reply' => 'Replies', 'like' => 'Likes', 'repost' => 'Reposts', 'bookmark' => 'Bookmarks', 'rsvp' => 'RSVPs', 'mention' => 'Mentions'];
$noun        = $statuses[$status];
?>
<?php if ($notice !== null) { ?>
    <p class="notice"><?= $notice ?></p>
<?php } ?>

<section class="card">
    <h2>Mentions</h2>

    <nav class="tabs" aria-label="State">
        <?php foreach ($statuses as $key => $label) { ?>
            <a href="<?= $href(array_filter([...$query, 'status' => $key === 'published' ? null : $key], static fn ($v): bool => $v !== null && $v !== '')) ?>"<?= $key === $status ? ' aria-current="page"' : '' ?>><?= $label ?></a>
        <?php } ?>
    </nav>

    <form action="/mentions" method="get" class="filters">
        <?php if ($status !== 'published') { ?><input type="hidden" name="status" value="<?= $status ?>"><?php } ?>
        <?php if (count($sites) > 1) { ?>
            <label class="field"><span class="label">Site</span>
                <select name="site">
                    <option value="">All sites</option>
                    <?php foreach ($sites as $s) { ?>
                        <option value="<?= $s['id'] ?>"<?= $site === $s['id'] ? ' selected' : '' ?>><?= $s['domain'] ?><?= $s['archived'] ? ' (archived)' : '' ?></option>
                    <?php } ?>
                </select>
            </label>
        <?php } ?>
        <label class="field"><span class="label">Kind</span>
            <select name="type">
                <option value="">All kinds</option>
                <?php foreach ($types as $t) { ?>
                    <option value="<?= $t ?>"<?= $type === $t ? ' selected' : '' ?>><?= $typeLabels[$t] ?></option>
                <?php } ?>
            </select>
        </label>
        <label class="field grow"><span class="label">From domain</span>
            <input type="text" name="domain" value="<?= $domain ?>" placeholder="example.com" autocapitalize="off" spellcheck="false">
        </label>
        <div class="form-actions">
            <button type="submit" class="secondary">Filter</button>
            <?php if ($query !== [] && array_keys($query) !== ['status']) { ?>
                <a class="button secondary" href="<?= $href(array_filter(['status' => $status === 'published' ? null : $status], static fn ($v): bool => $v !== null)) ?>">Clear</a>
            <?php } ?>
        </div>
    </form>

    <p class="muted small filter-count">
        <?= number_format($total) ?> <?= lcfirst($noun) ?> webmention<?= $total === 1 ? '' : 's' ?><?php
            if ($domain !== '') { ?> from <?= $domain ?><?php }
            if ($site !== null) { foreach ($sites as $s) { if ($s['id'] === $site) { ?> to <?= $s['domain'] ?><?php } } }
        ?>.
        <?php if ($status === 'hidden') { ?>Hidden by your mute rules on the <a href="/settings/blocks">Blocklists</a> page; "Show" publishes one without changing the rule.<?php } ?>
        <?php if ($status === 'deleted') { ?>Restoring one publishes it again and unblocks its source URL.<?php } ?>
    </p>

    <?php if ($links === []) { ?>
        <p class="muted">Nothing here.</p>
    <?php } else { ?>
        <?php if ($bulk) { require __DIR__ . '/_bulk.php'; } ?>

        <ul class="mention-list<?= $bulk ? ' selectable' : '' ?>">
            <?php foreach ($links as $link) { require __DIR__ . '/_row.php'; } ?>
        </ul>

        <?php $pagerLabel = 'Mention pages'; require __DIR__ . '/_pager.php'; ?>
    <?php } ?>
</section>
