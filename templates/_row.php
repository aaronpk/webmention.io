<?php
/**
 * One mention in a list. Included with `require` (not rendered as a partial)
 * so $link, already escaped by the including template, is not escaped twice.
 *
 * @var array       $link         See DashboardController::row().
 * @var bool        $show_delete  Show the × delete button (default true).
 * @var string|null $csrf         Needed for the review actions on a pending mention.
 * @var string|null $back         Where the review actions return to (default /dashboard).
 */
$awaiting = ($link["status"] ?? null) === "pending" && isset($csrf);
?>
<li class="mention-row<?= $awaiting ? ' pending' : '' ?>">
    <?php if ($link['author_url'] !== null) { ?><a href="<?= $link['author_url'] ?>" title="<?= $link['author_name'] ?>" rel="nofollow noopener"><?php } ?>
        <?php if ($link['photo'] !== null) { ?>
            <img class="avatar" src="<?= $link['photo'] ?>" alt="" loading="lazy">
        <?php } else { ?>
            <span class="avatar" aria-hidden="true"></span>
        <?php } ?>
    <?php if ($link['author_url'] !== null) { ?></a><?php } ?>

    <div class="body">
        <div class="byline">
            <?php if ($link['author_name'] !== '') { ?>
                <strong><?= $link['author_name'] ?></strong>
            <?php } else { ?>
                <strong class="muted"><?= $link['source_host'] ?></strong>
            <?php } ?>
            <span class="kind"><?= $link['kind'] ?></span>
            <?php if ($link['target_url'] !== null) { ?>
                <a href="<?= $link['target_url'] ?>" class="target"><span class="muted"><?= $link['target_host'] ?></span><?= $link['target_path'] ?></a>
            <?php } else { ?>
                <span class="target"><span class="muted"><?= $link['target_host'] ?></span><?= $link['target_path'] ?></span>
            <?php } ?>
        </div>

        <?php if ($link['name'] !== null) { ?>
            <p class="excerpt"><strong><?= $link['name'] ?></strong><?= $link['excerpt'] !== null ? ' — ' . $link['excerpt'] : '' ?></p>
        <?php } elseif ($link['excerpt'] !== null) { ?>
            <p class="excerpt"><?= $link['excerpt'] ?></p>
        <?php } ?>

        <div class="meta muted small">
            <?php if ($link['source_url'] !== null) { ?>
                <a href="<?= $link['source_url'] ?>" rel="nofollow noopener"><?= $link['href'] ?></a>
            <?php } else { ?>
                <?= $link['href'] ?>
            <?php } ?>
            <?php if ($link['published'] !== null) { ?> · published <?= $link['published'] ?><?php } ?>
            <?php if ($link['received'] !== null) { ?> · received <?= $link['received'] ?><?php } ?>
        </div>

        <?php if ($awaiting) { ?>
            <div class="review-actions">
                <form action="/approve" method="post" class="inline">
                    <input type="hidden" name="id" value="<?= $link['id'] ?>">
                    <input type="hidden" name="back" value="<?= $back ?? '/dashboard' ?>">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <button type="submit" class="small">Approve</button>
                </form>
                <form action="/approve" method="post" class="inline">
                    <input type="hidden" name="domain" value="<?= $link['source_host'] ?>">
                    <input type="hidden" name="back" value="<?= $back ?? '/dashboard' ?>">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <button type="submit" class="secondary small" title="Approve every waiting mention from <?= $link['source_host'] ?>">Approve all from <?= $link['source_host'] ?></button>
                </form>
                <form action="/reject" method="post" class="inline">
                    <input type="hidden" name="id" value="<?= $link['id'] ?>">
                    <input type="hidden" name="back" value="<?= $back ?? '/dashboard' ?>">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <button type="submit" class="secondary small" title="Delete this webmention and block its source URL">Reject</button>
                </form>
                <?php if ($link['author_host'] !== null) { ?>
                    <form action="/mute" method="post" class="inline">
                        <input type="hidden" name="kind" value="author">
                        <input type="hidden" name="pattern" value="<?= $link['author_host'] ?>">
                        <input type="hidden" name="back" value="<?= $back ?? '/dashboard' ?>">
                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                        <button type="submit" class="secondary small" title="Hide everything by authors on <?= $link['author_host'] ?>, without deleting">Mute author</button>
                    </form>
                <?php } ?>
            </div>
        <?php } ?>
    </div>

    <?php if (!$awaiting && ($show_delete ?? true)) { ?>
        <a class="button secondary small" href="<?= $link['delete_url'] ?>" title="Delete this webmention" aria-label="Delete">×</a>
    <?php } else { ?>
        <span></span>
    <?php } ?>
</li>
