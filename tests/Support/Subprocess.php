<?php

declare(strict_types=1);

namespace Webmention\Tests\Support;

use Webmention\Bootstrap;
use Webmention\Config;

/**
 * Starts the app's own executables (bin/worker, the PHP dev server) against
 * the test database and Redis, for tests that need a real process.
 */
final class Subprocess
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    private string $output = '';

    /** @param list<string> $command */
    public function __construct(array $command)
    {
        $env = [...getenv(), ...self::testEnvironment()];

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, Bootstrap::root(), $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start ' . implode(' ', $command));
        }

        $this->process = $process;
        $this->pipes   = $pipes;

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
    }

    /**
     * Every variable from .env.testing, so settings in the real .env (which the
     * app also reads) are overridden. Avatar archiving is switched off.
     *
     * @return array<string, string>
     */
    public static function testEnvironment(): array
    {
        return [
            ...Config::parse((string) file_get_contents(Bootstrap::root() . '/.env.testing')),
            'CA3DB_API_ENDPOINT' => '',
            'LOG_FILE'           => sys_get_temp_dir() . '/webmention-test.log',
        ];
    }

    public function pid(): int
    {
        return (int) proc_get_status($this->process)['pid'];
    }

    public function running(): bool
    {
        return proc_get_status($this->process)['running'];
    }

    public function signal(int $signal): void
    {
        proc_terminate($this->process, $signal);
    }

    /** Wait up to $seconds for the process to exit. Returns true if it did. */
    public function waitForExit(float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if (!$this->running()) {
                return true;
            }
            usleep(50_000);
        }

        return false;
    }

    /** Everything written to stdout and stderr so far. */
    public function output(): string
    {
        $this->output .= (string) stream_get_contents($this->pipes[1]) . (string) stream_get_contents($this->pipes[2]);

        return $this->output;
    }

    public function stop(): void
    {
        if ($this->running()) {
            proc_terminate($this->process, SIGKILL);
            $this->waitForExit(5);
        }
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($this->process);
    }

    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new \RuntimeException('No free port');
        }
        $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);

        return $port;
    }

    /** Poll until $check returns something other than null, or fail after $seconds. */
    public static function waitFor(callable $check, float $seconds, string $what): mixed
    {
        $deadline = microtime(true) + $seconds;
        do {
            $value = $check();
            if ($value !== null) {
                return $value;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException("Timed out waiting for $what");
    }
}
