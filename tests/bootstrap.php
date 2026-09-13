<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

// Integration tests read .env.testing (see README). It points at a disposable
// database and Redis DB 15, both wiped by the tests.
