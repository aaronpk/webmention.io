<?php

declare(strict_types=1);

/**
 * A tiny origin for transport tests, run with `php -S 127.0.0.1:PORT tests/Support/server.php`.
 *
 *   /big?mb=N            N megabytes of body
 *   /echo                the request method, headers and body as JSON
 *   /redirect?to=&code=  a redirect
 *   /slow?ms=&then=      sleep, then redirect to `then` (or answer "slow")
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

switch ($path) {
    case '/big':
        header('Content-Type: application/octet-stream');
        $chunk = str_repeat('x', 65536);
        for ($i = 0, $n = (int) ($_GET['mb'] ?? 5) * 16; $i < $n; $i++) {
            echo $chunk;
            flush();
        }
        exit;

    case '/echo':
        header('Content-Type: application/json');
        echo json_encode([
            'method'  => $_SERVER['REQUEST_METHOD'],
            'headers' => function_exists('getallheaders') ? getallheaders() : [],
            'body'    => file_get_contents('php://input'),
        ]);
        exit;

    case '/redirect':
        http_response_code((int) ($_GET['code'] ?? 302));
        header('Location: ' . (string) ($_GET['to'] ?? '/echo'));
        exit;

    case '/slow':
        usleep((int) ($_GET['ms'] ?? 0) * 1000);
        if (isset($_GET['then'])) {
            http_response_code(302);
            header('Location: ' . (string) $_GET['then']);
            exit;
        }
        echo 'slow';
        exit;

    default:
        http_response_code(404);
        echo 'nope';
}
