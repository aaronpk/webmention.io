<?php

declare(strict_types=1);

namespace Webmention\Logging;

use Throwable;

/**
 * Appends lines to a log file, or to stderr when no file is configured.
 *
 * Every process gets its own file under LOG_DIR: the web app writes web.log,
 * and each worker writes worker-{name}.log (see Bootstrap::logFile()). If the
 * file cannot be written, output falls back to error_log() rather than being
 * dropped.
 */
final class Log
{
    public function __construct(private readonly ?string $path = null)
    {
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function warning(string $message): void
    {
        $this->write('WARN', $message);
    }

    public function exception(Throwable $e, string $context = ''): void
    {
        $this->write('ERROR', trim($context . ' ' . sprintf(
            "%s: %s (%s:%d)\n%s",
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        )));
    }

    private function write(string $level, string $message): void
    {
        $line = sprintf("[%s] %s %s\n", gmdate('Y-m-d\TH:i:s\Z'), $level, $message);

        if ($this->path === null) {
            file_put_contents('php://stderr', $line);

            return;
        }

        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (@file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(rtrim($line));
        }
    }
}
