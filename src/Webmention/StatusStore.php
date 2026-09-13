<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use Redis;
use Webmention\Http\JsonResponder;

/**
 * The status document behind each status URL, kept in Redis for three days.
 * Key names are unchanged from the Ruby app, so status URLs it handed out keep
 * working after the switch.
 */
final class StatusStore
{
    public const TTL = 259200;

    public function __construct(private readonly Redis $redis)
    {
    }

    /** @param array<string, mixed> $status */
    public function set(string $token, array $status): void
    {
        $this->redis->setex(self::key($token), self::TTL, JsonResponder::encode($status));
    }

    /** Record a failure. The summary is omitted when there is no description, as before. */
    public function error(string $token, string $source, string $target, string $error, ?string $description = null): void
    {
        $status = ['status' => $error, 'source' => $source, 'target' => $target];
        if ($description !== null) {
            $status['summary'] = $description;
        }

        $this->set($token, $status);
    }

    /** Decoded as objects, so an empty `data: {}` stays an object when re-encoded. */
    public function get(string $token): ?object
    {
        $json = $this->redis->get(self::key($token));
        if (!is_string($json)) {
            return null;
        }

        $decoded = json_decode($json);

        return is_object($decoded) ? $decoded : null;
    }

    private static function key(string $token): string
    {
        return 'webmention:status:' . $token;
    }
}
