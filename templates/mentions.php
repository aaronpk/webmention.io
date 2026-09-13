<?php
/**
 * /api/mentions.html: an h-feed of mentions, for feed readers and embedding.
 * Markup follows the old page so existing consumers keep parsing it.
 *
 * @var string|null $account
 * @var list<array> $links   See ApiController::feedEntry().
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $account !== null ? $account . ' — ' : '' ?>Mentions</title>
    <link rel="stylesheet" href="/assets/mentions.css">
</head>
<body>
<div class="mentions h-feed">
    <?php if ($account !== null) { ?>
        <h1 class="p-name"><?= $account ?></h1>
    <?php } ?>

    <?php foreach ($links as $link) { ?>
        <div class="h-entry mention">
            <div class="context">
                ↩ <a href="<?= $link['target'] ?>" class="<?= $link['relation_class'] ?>"><?= $link['target'] ?></a>
            </div>

            <?php if ($link['has_author']) { ?>
                <div class="author u-author h-card">
                    <?php if ($link['author_photo'] !== null) { ?>
                        <img src="<?= $link['author_photo'] ?>" class="photo u-photo" alt="">
                    <?php } ?>
                    <?php if ($link['author_url'] !== null) { ?>
                        <a href="<?= $link['author_url'] ?>" class="name u-url p-name"><?= $link['author_name'] ?></a>
                        <a href="<?= $link['author_url'] ?>" class="url"><?= $link['author_url'] ?></a>
                    <?php } elseif ($link['author_name'] !== '') { ?>
                        <span class="name p-name"><?= $link['author_name'] ?></span>
                    <?php } ?>
                </div>
            <?php } ?>

            <?php if ($link['name'] !== null) { ?>
                <h1 class="p-name"><?= $link['name'] ?></h1>
            <?php } ?>

            <?php if ($link['content_html'] !== null) { ?>
                <div class="e-content html"><?= $link['content_html'] ?></div>
            <?php } elseif ($link['content_text'] !== null) { ?>
                <div class="e-content plaintext"><?= $link['content_text'] ?></div>
            <?php } ?>

            <div class="metaline">
                <time class="dt-published" datetime="<?= $link['datetime'] ?>">
                    <?php if ($link['url'] !== null) { ?>
                        <a href="<?= $link['url'] ?>" class="u-url"><?= $link['date_label'] ?></a>
                    <?php } else { ?>
                        <?= $link['date_label'] ?>
                    <?php } ?>
                </time>
            </div>
        </div>
    <?php } ?>
</div>
</body>
</html>
