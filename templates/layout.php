<?php
/**
 * @var string      $title
 * @var string      $content Pre-rendered, already-escaped markup.
 * @var array|null  $nav     ['domain' => ?string, 'active' => string, 'csrf' => string] when signed in.
 */
$nav = $nav ?? null;
$links = [
    'dashboard' => ['/dashboard', 'Dashboard'],
    'sites'     => ['/settings/sites', 'Sites'],
    'blocks'    => ['/settings/blocks', 'Blocklists'],
    'webhooks'  => ['/settings/webhooks', 'Web Hooks'],
    'settings'  => ['/settings', 'Settings'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title ?></title>
    <link rel="stylesheet" href="/assets/app.css">
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
                <nav class="nav" aria-label="Account">
                    <?php foreach ($links as $key => [$href, $label]) { ?>
                        <a href="<?= $href ?>"<?= $nav['active'] === $key ? ' aria-current="page"' : '' ?>><?= $label ?></a>
                    <?php } ?>
                </nav>
                <div class="account">
                    <span class="muted"><?= $nav['domain'] ?></span>
                    <form action="/logout" method="post" class="inline">
                        <input type="hidden" name="csrf" value="<?= $nav['csrf'] ?>">
                        <button type="submit" class="link">Sign out</button>
                    </form>
                </div>
            <?php } ?>
        </div>
    </header>

    <main class="shell">
        <?= $content ?>
    </main>

    <footer class="site-footer">
        <a href="https://github.com/aaronpk/webmention.io">Open source</a>
        <span aria-hidden="true">·</span>
        <span>Made by <a href="https://aaronparecki.com">aaronpk</a></span>
        <span aria-hidden="true">·</span>
        <a href="https://indieweb.org/webmention.io">indieweb.org/webmention.io</a>
    </footer>

    <script src="/assets/app.js"></script>
</body>
</html>
