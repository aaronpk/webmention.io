<?php
/**
 * @var list<string> $domains
 * @var string       $csrf
 */
?>
<section class="card">
    <h2>Blocked domains</h2>

    <?php if ($domains === []) { ?>
        <p class="muted">You haven't blocked any domains.</p>
    <?php } else { ?>
        <div class="table-wrap">
            <table class="data">
                <tbody>
                    <?php foreach ($domains as $domain) { ?>
                        <tr>
                            <td><?= $domain ?></td>
                            <td style="text-align: right">
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
    <h2>Block a domain</h2>
    <p class="muted">Enter the URL of a webmention you'd like to block and delete. You'll be able to confirm in the next step.</p>
    <form action="/delete" method="get" class="inline-field">
        <input type="url" name="source" placeholder="https://spam.example/post" required aria-label="Source URL">
        <button type="submit" class="secondary">Preview delete</button>
    </form>
</section>
