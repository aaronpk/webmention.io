<?php
/**
 * Newer / Older links under a paged list. Included with `require`.
 *
 * @var int      $page        Zero-based.
 * @var int      $pages
 * @var callable $pageQuery   int => href for that page (unescaped; escaped here).
 * @var string   $pagerLabel  For aria-label.
 */
?>
<?php if ($pages > 1) { ?>
    <nav class="pager" aria-label="<?= $pagerLabel ?>">
        <?php if ($page > 0) { ?><a href="<?= htmlspecialchars($pageQuery($page - 1), ENT_QUOTES | ENT_HTML5) ?>">&larr; Newer</a><?php } else { ?><span></span><?php } ?>
        <span class="muted">Page <?= $page + 1 ?> of <?= $pages ?></span>
        <?php if ($page + 1 < $pages) { ?><a href="<?= htmlspecialchars($pageQuery($page + 1), ENT_QUOTES | ENT_HTML5) ?>">Older &rarr;</a><?php } else { ?><span></span><?php } ?>
    </nav>
<?php } ?>
