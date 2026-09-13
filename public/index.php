<?php

declare(strict_types=1);

use Webmention\Bootstrap;
use Webmention\Http\Request;
use Webmention\Logging\ErrorHandler;

// When running under `php -S` with this file as the router script, let the
// built-in server handle real files (CSS, JS, images) itself.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . '/' . ltrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
    if ($file !== __DIR__ . '/' && is_file($file)) {
        return false;
    }
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Dependencies are not installed. Run:\n\n    composer install\n";
    exit(1);
}

require $autoload;

date_default_timezone_set('UTC');
ErrorHandler::ignoreVendorNoise();

$config    = Bootstrap::config();
$container = Bootstrap::container($config);

Bootstrap::kernel($container)->handle(Request::fromGlobals($config->trustProxy()))->send();
