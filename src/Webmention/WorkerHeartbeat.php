<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use Redis;

/**
 * Which workers are running, for the admin overview.
 *
 * One Redis hash, worker name => a small JSON record refreshed on every turn
 * of the worker's loop. A hash rather than a key per worker with a TTL,
 * because a worker that has stopped is exactly what an admin needs to see:
 * it stays listed with the time it was last heard from instead of vanishing.
 */
final class WorkerHeartbeat
{
    public const KEY = 'webmention:workers';

    /** A worker not heard from for this long is no longer running. */
    public const STALE_AFTER = 60;

    public function __construct(private readonly Redis $redis)
    {
    }

    public function beat(string $name, int $jobsDone): void
    {
        $this->redis->hSet(self::KEY, $name === '' ? 'worker' : $name, (string) json_encode([
            'at'   => time(),
            'pid'  => getmypid(),
            'jobs' => $jobsDone,
        ]));
    }

    /**
     * Every worker ever seen, the ones heard from most recently first.
     *
     * @return list<array{name: string, at: int, pid: int, jobs: int, alive: bool, ago: int}>
     */
    public function all(): array
    {
        $now  = time();
        $out  = [];
        $hash = $this->redis->hGetAll(self::KEY);

        foreach (is_array($hash) ? $hash : [] as $name => $json) {
            $record = json_decode((string) $json, true);
            $at     = is_array($record) ? (int) ($record['at'] ?? 0) : 0;

            $out[] = [
                'name'  => (string) $name,
                'at'    => $at,
                'pid'   => is_array($record) ? (int) ($record['pid'] ?? 0) : 0,
                'jobs'  => is_array($record) ? (int) ($record['jobs'] ?? 0) : 0,
                'alive' => $at > 0 && $now - $at <= self::STALE_AFTER,
                'ago'   => $at > 0 ? max(0, $now - $at) : 0,
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return $out;
    }

    /** Drop a worker that will not come back, so the list does not grow stale names. */
    public function forget(string $name): void
    {
        $this->redis->hDel(self::KEY, $name);
    }
}
