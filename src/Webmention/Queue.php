<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use Redis;
use Webmention\Http\JsonResponder;

/**
 * Webmentions waiting to be verified. The web request pushes a job; bin/worker
 * pops it. A plain Redis list: first in, first out.
 */
final class Queue
{
    public const KEY = 'webmention:queue';

    public function __construct(private readonly Redis $redis)
    {
    }

    public function push(Job $job): void
    {
        $this->redis->lPush(self::KEY, JsonResponder::encode($job->toArray()));
    }

    /** Wait up to $timeout seconds for a job. */
    public function pop(int $timeout): ?Job
    {
        $result = $this->redis->brPop([self::KEY], $timeout);

        if (!is_array($result) || !isset($result[1]) || !is_string($result[1])) {
            return null;
        }

        $data = json_decode($result[1], true);

        return is_array($data) ? Job::fromArray($data) : null;
    }

    public function length(): int
    {
        return (int) $this->redis->lLen(self::KEY);
    }
}
