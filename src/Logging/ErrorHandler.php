<?php

declare(strict_types=1);

namespace Webmention\Logging;

/**
 * Some dependencies (PicoFeed inside XRay in particular) raise warnings and
 * deprecation notices on ordinary third-party pages. They aren't actionable
 * and would drown the logs, so they are dropped. Anything raised by our own
 * code still goes to PHP's normal error handling.
 *
 * Dropped warnings are counted, and the worker logs the count per job, so a
 * page that upsets a parser still leaves a trace.
 */
final class ErrorHandler
{
    private const IGNORED = E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED;

    private static int $ignored = 0;

    private static string $last = '';

    public static function ignoreVendorNoise(): void
    {
        $vendor = dirname(__DIR__, 2) . '/vendor/';
        set_error_handler(
            static function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use ($vendor): bool {
                if (($errno & self::IGNORED) === 0 || !str_starts_with($errfile, $vendor)) {
                    return false;
                }
                self::$ignored++;
                self::$last = $errstr . ' in ' . substr($errfile, strlen($vendor)) . ':' . $errline;

                return true;
            },
        );
    }

    /**
     * How many warnings were dropped since the last call, and the last one.
     *
     * @return array{int, string}
     */
    public static function drain(): array
    {
        [$count, $last] = [self::$ignored, self::$last];
        self::$ignored  = 0;
        self::$last     = '';

        return [$count, $last];
    }
}
