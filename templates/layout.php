<?php
/**
 * @var string      $title
 * @var string      $content Pre-rendered, already-escaped markup.
 * @var array|null  $nav     ['domain' => ?string, 'active' => string, 'csrf' => string] when signed in.
 */
$nav = $nav ?? null;
$links = [
    'dashboard' => ['/dashboard', 'Dashboard'],
    'mentions'  => ['/mentions', 'Mentions'],
    'sources'   => ['/sources', 'Sources'],
    'sites'     => ['/settings/sites', 'Sites'],
    'blocks'    => ['/settings/blocks', 'Blocklists'],
    'settings'  => ['/settings', 'Settings'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title ?></title>
    <link rel="stylesheet" href="<?= $view->asset('/assets/app.css') ?>">
    <link rel="icon" href="/favicon.ico">
    <link rel="manifest" href="/manifest.json">
</head>
<body>
    <header class="site-header">
        <div class="bar">
            <a class="brand" href="/">
                <img src="/img/webmention-logo.svg" alt="" width="28" height="28">
                <span>Webmention.io</span>
            </a>
            <?php if ($nav !== null) { ?>
                <input type="checkbox" id="menu" class="menu-toggle" aria-label="Menu" aria-controls="account-nav">
                <label for="menu" class="menu-button"><span class="bars" aria-hidden="true"></span>Menu<?php if (($nav['pending'] ?? 0) > 0) { ?> <span class="count" title="Awaiting review"><?= $nav['pending'] ?></span><?php } ?></label>
                <div class="account">
                    <span class="muted"><?= $nav['domain'] ?></span>
                    <form action="/logout" method="post" class="inline">
                        <input type="hidden" name="csrf" value="<?= $nav['csrf'] ?>">
                        <button type="submit" class="link">Sign out</button>
                    </form>
                </div>
                <nav class="nav" id="account-nav" aria-label="Account">
                    <?php foreach ($links as $key => [$href, $label]) { ?>
                        <a href="<?= $href ?>"<?= $nav['active'] === $key ? ' aria-current="page"' : '' ?>><?= $label ?><?php if ($key === 'dashboard' && ($nav['pending'] ?? 0) > 0) { ?> <span class="count" title="Awaiting review"><?= $nav['pending'] ?></span><?php } ?></a>
                    <?php } ?>
                </nav>
            <?php } ?>
        </div>
    </header>

    <main class="shell">
        <?= $content ?>
    </main>

    <footer class="site-footer">
        <a href="/api">API docs</a>
        <span aria-hidden="true">·</span>
        <a href="https://github.com/aaronpk/webmention.io">Open source</a>
        <span aria-hidden="true">·</span>
        <span>Made by <a href="https://aaronparecki.com">aaronpk</a></span>
        <span aria-hidden="true">·</span>
        <a href="https://indieweb.org/webmention.io">indieweb.org/webmention.io</a>
    </footer>

    <script src="<?= $view->asset('/assets/app.js') ?>"></script>
</body>
</html>
