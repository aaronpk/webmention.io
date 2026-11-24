<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use Redis;
use Throwable;

/**
 * Counters for the Munin graphs: what the endpoint did with each webmention,
 * and what the workers made of it.
 *
 * Each is a Redis hash of ever-increasing counts, read by Munin as DERIVE
 * fields, so Munin works out the rate and nothing here needs trimming. Only
 * the names listed are kept; anything else counts as "other", since error
 * codes can come from a remote token endpoint and would otherwise add a
 * field per distinct string.
 */
final class Stats
{
    public const KEY_RECEIVED  = 'webmention:stats:received';
    public const KEY_PROCESSED = 'webmention:stats:processed';

    public const RECEIVED = ['accepted', 'invalid_request', 'invalid_target', 'rate_limited', 'queue_full', 'other'];

    public const PROCESSED = [
        'success', 'deleted', 'no_link_found', 'not_found', 'gone', 'invalid_source', 'invalid_target',
        'target_not_found', 'timeout', 'dns_error', 'connect_error', 'ssl_error', 'ssl_cert_error',
        'too_many_redirects', 'no_content', 'invalid_content', 'forbidden', 'unauthorized', 'parse_error',
        'access_token_error', 'blocked', 'internal_error', 'other',
    ];

    public function __construct(private readonly Redis $redis)
    {
    }

    public function received(string $outcome): void
    {
        $this->count(self::KEY_RECEIVED, self::RECEIVED, $outcome);
    }

    public function processed(string $result): void
    {
        $this->count(self::KEY_PROCESSED, self::PROCESSED, $result);
    }

    /** Which received counter an endpoint response belongs to. */
    public static function receivedOutcome(int $status): string
    {
        return match (true) {
            $status < 400 => 'accepted',
            $status === 400 => 'invalid_request',
            $status === 404 => 'invalid_target',
            $status === 429 => 'rate_limited',
            $status === 503 => 'queue_full',
            default => 'other',
        };
    }

    /** @return array<string, int> Every listed name, zero when never counted. */
    public function receivedCounts(): array
    {
        return $this->counts(self::KEY_RECEIVED, self::RECEIVED);
    }

    /** @return array<string, int> */
    public function processedCounts(): array
    {
        return $this->counts(self::KEY_PROCESSED, self::PROCESSED);
    }

    /** @param list<string> $names */
    private function count(string $key, array $names, string $name): void
    {
        try {
            $this->redis->hIncrBy($key, in_array($name, $names, true) ? $name : 'other', 1);
        } catch (Throwable) {
            // A graph is never worth failing a webmention for.
        }
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, int>
     */
    private function counts(string $key, array $names): array
    {
        $hash = $this->redis->hGetAll($key);
        $out  = [];
        foreach ($names as $name) {
            $out[$name] = (int) (is_array($hash) ? ($hash[$name] ?? 0) : 0);
        }

        return $out;
    }
}
