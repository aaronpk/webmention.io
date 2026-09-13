<?php
/**
 * One mention in a list. Included with `require` (not rendered as a partial)
 * so $link, already escaped by the including template, is not escaped twice.
 *
 * @var array $link      See DashboardController::row().
 * @var bool  $show_delete
 */
?>
<li class="mention-row">
    <?php if ($link['author_url'] !== null) { ?><a href="<?= $link['author_url'] ?>" title="<?= $link['author_name'] ?>"><?php } ?>
        <?php if ($link['photo'] !== null) { ?>
            <img class="avatar" src="<?= $link['photo'] ?>" alt="<?= $link['author_name'] ?>" loading="lazy">
        <?php } else { ?>
            <span class="avatar" aria-hidden="true"></span>
        <?php } ?>
    <?php if ($link['author_url'] !== null) { ?></a><?php } ?>

    <div class="urls">
        <?php if ($link['source_url'] !== null) { ?>
            <a href="<?= $link['source_url'] ?>"><?= $link['href'] ?></a>
        <?php } else { ?>
            <?= $link['href'] ?>
        <?php } ?>
        <br>
        <span class="on"><?= $link['type'] ?></span>
        <?php if ($link['target_url'] !== null) { ?>
            <a href="<?= $link['target_url'] ?>"><?= $link['target'] ?></a>
        <?php } else { ?>
            <?= $link['target'] ?>
        <?php } ?>
        <?php if ($link['received'] !== null) { ?>
            <span class="muted small"> · <?= $link['received'] ?></span>
        <?php } ?>
    </div>

    <?php if ($show_delete ?? true) { ?>
        <a class="button secondary small" href="<?= $link['delete_url'] ?>" title="Delete this webmention" aria-label="Delete">×</a>
    <?php } else { ?>
        <span></span>
    <?php } ?>
</li>
