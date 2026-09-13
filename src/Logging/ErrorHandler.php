<?php

declare(strict_types=1);

namespace Webmention\Logging;

/**
 * Some dependencies (PicoFeed inside XRay in particular) raise warnings and
 * deprecation notices on ordinary third-party pages. They aren't actionable
 * and would drown the logs, so they are dropped. Anything raised by our own
 * code still goes to PHP's normal error handling.
 */
final class ErrorHandler
{
    private const IGNORED = E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED;

    public static function ignoreVendorNoise(): void
    {
        $vendor = dirname(__DIR__, 2) . '/vendor/';

        set_error_handler(
            static fn (int $errno, string $errstr, string $errfile = ''): bool => ($errno & self::IGNORED) !== 0 && str_starts_with($errfile, $vendor),
        );
    }
}
