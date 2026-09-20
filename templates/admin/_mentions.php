<?php
/**
 * A table of mentions as MentionFacts sees them. Required, not rendered as a
 * partial, so the rows are not escaped twice.
 *
 * @var list<array> $mentions
 */
?>
<div class="table-wrap">
    <table class="data admin-mentions">
        <thead>
            <tr>
                <th class="num">ID</th>
                <th>Source</th>
                <th>Target</th>
                <th>Kind</th>
                <th>State</th>
                <th>Received</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($mentions as $m) { ?>
                <tr>
                    <td class="num"><a href="/admin/lookup?q=<?= $m['id'] ?>"><?= $m['id'] ?></a></td>
                    <td class="url">
                        <?php if ($m['source_url'] !== null) { ?><a href="<?= $m['source_url'] ?>" rel="noopener nofollow"><?= $m['source'] ?></a><?php } else { ?><?= $m['source'] ?><?php } ?>
                        <?php if ($m['author_name'] !== null && $m['author_name'] !== '') { ?><br><span class="muted small"><?= $m['author_name'] ?></span><?php } ?>
                        <?php if ($m['excerpt'] !== null) { ?><br><span class="muted small"><?= $m['excerpt'] ?></span><?php } ?>
                    </td>
                    <td class="url"><?php if ($m['target_url'] !== null) { ?><a href="<?= $m['target_url'] ?>" rel="noopener nofollow"><?= $m['target'] ?></a><?php } else { ?><?= $m['target'] ?><?php } ?><?php if ($m['fragment'] !== null) { ?> <span class="muted small">#<?= $m['fragment'] ?></span><?php } ?></td>
                    <td class="nowrap"><?= $m['kind'] ?></td>
                    <td class="nowrap">
                        <span class="badge<?= in_array($m['status'], ['deleted', 'hidden'], true) ? ' badge-error' : '' ?>"><?= $m['status'] ?></span>
                        <?php if ($m['private']) { ?> <span class="badge" title="Content and author are not shown for a private webmention">private</span><?php } ?>
                    </td>
                    <td class="nowrap"><?= $m['created_at'] ?? '' ?></td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
</div>
