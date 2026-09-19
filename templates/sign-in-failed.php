<?php
/**
 * A sign-in that did not complete, stage by stage.
 *
 * @var string      $heading
 * @var string      $message  One sentence: what went wrong.
 * @var list<array> $stages   See SignInReport: stage, label, state, summary, details, hint.
 * @var string|null $me       The address entered, for trying again.
 */
$marks = ['ok' => '✓', 'failed' => '✗', 'skipped' => '–'];
$words = ['ok' => 'ok', 'failed' => 'failed', 'skipped' => 'not reached'];
?>
<section class="card">
    <h1><?= $heading ?></h1>
    <p class="alert"><?= $message ?></p>

    <h2>What happened</h2>
    <ol class="stages">
        <?php foreach ($stages as $s) { ?>
            <li class="stage <?= $s['state'] ?>">
                <span class="mark" aria-hidden="true"><?= $marks[$s['state']] ?></span>
                <div class="body">
                    <div class="head"><strong><?= $s['label'] ?></strong> <span class="muted small"><?= $words[$s['state']] ?></span></div>
                    <p class="summary"><?= $s['summary'] ?></p>
                    <?php if ($s['hint'] !== null) { ?>
                        <p class="hint"><?= $s['hint'] ?></p>
                    <?php } ?>
                    <?php if ($s['details'] !== []) { ?>
                        <details>
                            <summary>Details</summary>
                            <dl class="facts">
                                <?php foreach ($s['details'] as $d) { ?>
                                    <dt><?= $d['label'] ?></dt><dd><code><?= $d['value'] ?></code></dd>
                                <?php } ?>
                            </dl>
                        </details>
                    <?php } ?>
                </div>
            </li>
        <?php } ?>
    </ol>

    <p><a class="button" href="/<?= $me !== null ? '?me=' . rawurlencode(html_entity_decode($me, ENT_QUOTES | ENT_HTML5)) : '' ?>">Try again</a>
        <span class="muted small">Once your site or server is fixed, sign in again; the same page appears if it still fails, showing what changed.</span></p>
</section>
