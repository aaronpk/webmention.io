<?php
/**
 * The browser view of a JSON response from a public endpoint.
 *
 * @var string|null $error
 * @var string|null $description
 * @var string|null $status
 * @var string|null $source
 * @var string|null $target
 * @var string|null $summary
 * @var string|null $location
 * @var string      $json
 */
use Webmention\Http\JsonResponder;
?>
<section class="card narrow">
    <?php if ($error !== null) { ?>
        <p class="badge badge-error">Error</p>
        <h1><?= JsonResponder::titleize($error) ?></h1>
        <?php if ($description !== null) { ?>
            <p><?= $description ?></p>
        <?php } ?>
    <?php } elseif ($status !== null) { ?>
        <p class="badge">Status</p>
        <h1><?= JsonResponder::titleize($status) ?></h1>

        <dl class="facts">
            <dt>Source</dt>
            <dd><code><?= $source ?></code></dd>
            <dt>Target</dt>
            <dd><code><?= $target ?></code></dd>
        </dl>

        <?php if ($summary !== null) { ?>
            <p><?= $summary ?></p>
        <?php } ?>
        <?php if ($location !== null) { ?>
            <p><a class="button" href="<?= $location ?>">View status</a></p>
        <?php } ?>
    <?php } ?>

    <details<?= $error === null && $status === null ? ' open' : '' ?>>
        <summary>Response</summary>
        <pre><code><?= $json ?></code></pre>
    </details>
</section>
