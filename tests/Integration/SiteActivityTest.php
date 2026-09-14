<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Tests\Support\IntegrationTestCase;
use Webmention\Webmention\SiteActivity;

/**
 * The per-month sparkline on the site page: counts by id range, cached
 * month boundaries.
 */
final class SiteActivityTest extends IntegrationTestCase
{
    public function testCountsEachMonthFromIdRangesAndZeroFillsTheRest(): void
    {
        $alice = $this->createAccount('alice.example');
        $site  = $this->createSite($alice, 'alice.example');
        $other = $this->createSite($alice, 'other.example');

        $month = static fn (int $ago, int $day = 15): string => date('Y-m-', strtotime("first day of -$ago months")) . sprintf('%02d 12:00:00', $day);

        // Inserted oldest first, so ids follow created_at as they do in production.
        $this->createLink($site, 'https://alice.example/a', 'https://x.example/1', ['created_at' => $month(30)]); // before the window
        $this->createLink($site, 'https://alice.example/a', 'https://x.example/2', ['created_at' => $month(23, 1)]); // first day of the oldest month
        $this->createLink($other, 'https://other.example/', 'https://x.example/3', ['created_at' => $month(23, 2)]);
        $this->createLink($site, 'https://alice.example/a', 'https://x.example/4', ['created_at' => $month(5)]);
        $this->createLink($site, 'https://alice.example/a', 'https://x.example/5', ['created_at' => $month(5, 28)]);
        $this->createLink($site, 'https://alice.example/a', 'https://x.example/6', ['created_at' => $month(5, 28), 'deleted' => 1]); // received, then deleted: still volume
        $this->createLink($other, 'https://other.example/', 'https://x.example/7', ['created_at' => $month(1)]);
        $this->createLink($site, 'https://alice.example/a', 'https://x.example/8', ['created_at' => $month(0, 1)]); // this month

        $activity = $this->service(SiteActivity::class);
        $series   = $activity->monthlyCounts($site->id, 24);

        self::assertCount(25, $series, '24 past months plus the current one');
        self::assertSame(date('Y-m', strtotime('first day of -24 months')), $series[0]['month']);
        self::assertSame(date('Y-m'), $series[24]['month']);
        self::assertSame(date('M Y'), $series[24]['label']);

        $byMonth = array_column($series, 'count', 'month');
        self::assertSame(0, $byMonth[date('Y-m', strtotime('first day of -24 months'))], 'nothing that month; the older link is outside the window');
        self::assertSame(1, $byMonth[date('Y-m', strtotime('first day of -23 months'))]);
        self::assertSame(3, $byMonth[date('Y-m', strtotime('first day of -5 months'))]);
        self::assertSame(0, $byMonth[date('Y-m', strtotime('first day of -1 months'))], 'the other site\'s link is not counted');
        self::assertSame(1, $byMonth[date('Y-m')]);
        self::assertSame(5, array_sum($byMonth));

        // The other site: one 23 months ago (index 1) and one last month (index 23).
        $expected = array_fill(0, 25, 0);
        $expected[1] = 1;
        $expected[23] = 1;
        self::assertSame($expected, array_column($activity->monthlyCounts($other->id, 24), 'count'));
    }

    public function testMonthStartsAreCachedOnceTheCurrentMonthHasOne(): void
    {
        $alice = $this->createAccount('alice.example');
        $site  = $this->createSite($alice, 'alice.example');
        $activity = $this->service(SiteActivity::class);
        $key = 'webmention:month-ids:' . gmdate('Y-m-d') . ':3';

        // An empty table: no month has a start, every count is zero, and nothing is cached yet.
        self::assertSame([null, null, null, null], array_values($activity->monthStarts(3)));
        self::assertSame([0, 0, 0, 0], array_column($activity->monthlyCounts($site->id, 3), 'count'));
        self::assertSame(0, $this->redis->exists($key));

        // The first link of the month shows up at once, and now the map is cached for the day.
        $this->createLink($site, 'https://alice.example/a', 'https://x.example/1');
        self::assertSame([0, 0, 0, 1], array_column($activity->monthlyCounts($site->id, 3), 'count'));
        self::assertSame(1, $this->redis->exists($key));

        // Later rows have larger ids, so they land in the current month without recomputing.
        $this->createLink($site, 'https://alice.example/a', 'https://x.example/2');
        self::assertSame([0, 0, 0, 2], array_column($activity->monthlyCounts($site->id, 3), 'count'));
    }

    public function testAMonthWithNoRowsAnywhereDoesNotSwallowTheMonthBefore(): void
    {
        $alice = $this->createAccount('alice.example');
        $site  = $this->createSite($alice, 'alice.example');
        $lastMonth = date('Y-m-', strtotime('first day of -1 months')) . '10 12:00:00';

        // Everything in the table is from last month; the current month has no start id.
        $this->createLink($site, 'https://alice.example/a', 'https://x.example/1', ['created_at' => $lastMonth]);
        $this->createLink($site, 'https://alice.example/a', 'https://x.example/2', ['created_at' => $lastMonth]);

        $series = $this->service(SiteActivity::class)->monthlyCounts($site->id, 2);
        self::assertSame([0, 2, 0], array_column($series, 'count'));
    }
}
