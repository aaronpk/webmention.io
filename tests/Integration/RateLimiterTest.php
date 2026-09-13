<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Tests\Support\IntegrationTestCase;
use Webmention\Webmention\RateLimiter;

final class RateLimiterTest extends IntegrationTestCase
{
    public function testCountsPerSubjectWithinAWindow(): void
    {
        $limiter = $this->service(RateLimiter::class);

        self::assertTrue($limiter->allow('t', '192.0.2.1', 2, 60));
        self::assertTrue($limiter->allow('t', '192.0.2.1', 2, 60));
        self::assertFalse($limiter->allow('t', '192.0.2.1', 2, 60));
        self::assertFalse($limiter->allow('t', '192.0.2.1', 2, 60));

        // Other subjects and other counters are unaffected.
        self::assertTrue($limiter->allow('t', '192.0.2.2', 2, 60));
        self::assertTrue($limiter->allow('u', '192.0.2.1', 2, 60));

        $keys = $this->redis->keys('webmention:ratelimit:t:*');
        self::assertCount(2, $keys);
        self::assertGreaterThan(0, $this->redis->ttl($keys[0]));
    }
}
