<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use Redis;
use Webmention\Logging\Log;

/**
 * Fixed-window counters in Redis.
 *
 * Every webmention costs the service several outgoing requests, and the
 * per-(source, target) lock alone can be sidestepped by varying a query
 * parameter. So the endpoints also count by client address and by source
 * host. Limits are deliberately loose; the aim is to stop one client from
 * filling the queue or using the service to hammer a third party, not to
 * meter ordinary senders. Bridgy alone sends bursts of hundreds.
 */
final class RateLimiter
{
    public function __construct(
        private readonly Redis $redis,
        private readonly Log $log,
    ) {
    }

    /**
     * Count one request for $subject under $name and say whether it is within
     * $limit per $seconds. The first refusal in a window is logged so limits
     * can be tuned from the log.
     */
    public function allow(string $name, string $subject, int $limit, int $seconds): bool
    {
        $window = intdiv(time(), $seconds);
        $key    = sprintf('webmention:ratelimit:%s:%s:%d', $name, md5($subject), $window);

        $count = (int) $this->redis->incr($key);
        if ($count === 1) {
            $this->redis->expire($key, $seconds + 1);
        }

        if ($count > $limit) {
            if ($count === $limit + 1) {
                $this->log->info("Rate limit '$name' exceeded by $subject ($limit per {$seconds}s)");
            }

            return false;
        }

        return true;
    }
}
