<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Model\Account;
use Webmention\Model\Site;
use Webmention\Tests\Support\IntegrationTestCase;
use Webmention\Webmention\AccountOverview;

/**
 * The strip at the top of the dashboard: the last 30 days by kind.
 */
final class DashboardOverviewTest extends IntegrationTestCase
{
    private Account $alice;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = $this->createAccount('alice.example');
        $this->site  = $this->createSite($this->alice, 'alice.example');
    }

    private function at(string $ago): string
    {
        return gmdate('Y-m-d H:i:s', strtotime($ago));
    }

    public function testCountsByKindAgainstThePreviousWindow(): void
    {
        $mention = fn (string $source, string $type, string $ago, array $extra = []): int =>
            $this->createLink($this->site, 'https://alice.example/post', $source, ['type' => $type, 'created_at' => $this->at($ago), ...$extra]);

        $mention('https://a.example/1', 'reply', '-1 day');
        $mention('https://a.example/2', 'reply', '-20 days');
        $mention('https://a.example/3', 'like', '-2 days');
        $mention('https://a.example/4', 'rsvp-yes', '-3 days');
        $mention('https://a.example/5', 'rsvp-no', '-4 days');
        $mention('https://a.example/6', 'link', '-5 days');
        $mention('https://a.example/7', 'reply', '-35 days');
        $mention('https://a.example/8', 'repost', '-50 days');
        $mention('https://a.example/9', 'reply', '-70 days');
        $mention('https://a.example/10', 'reply', '-1 hour', ['verified' => 0, 'status' => 'pending']);
        $mention('https://a.example/11', 'like', '-1 hour', ['deleted' => 1]);
        $mention('https://a.example/12', 'like', '-1 hour', ['verified' => 0, 'status' => 'hidden']);
        $this->createLink($this->createSite($this->createAccount('mallory.example'), 'mallory.example'), 'https://mallory.example/x', 'https://a.example/13', ['type' => 'reply']);

        $overview = $this->service(AccountOverview::class)->recent($this->alice->id);
        self::assertSame(30, $overview['days']);
        self::assertSame(6, $overview['total'], 'published only, this window');
        self::assertSame(2, $overview['before']);
        $counts = [];
        foreach ($overview['kinds'] as $k) {
            $counts[$k['type']] = [$k['count'], $k['before']];
        }
        self::assertSame([
            'reply'    => [2, 1],
            'like'     => [1, 0],
            'repost'   => [0, 1],
            'bookmark' => [0, 0],
            'rsvp'     => [2, 0],
            'mention'  => [1, 0],
        ], $counts);

        $this->signIn($this->alice);
        $body = $this->request('GET', '/dashboard')->body;
        self::assertStringContainsString('<h2>Last 30 days</h2>', $body);
        self::assertStringContainsString('<a href="/mentions?type=reply">', $body);
        self::assertStringContainsString('<span class="stat-count">2</span>', $body);
        self::assertStringContainsString('<span class="stat-label">Replies</span>', $body);
        self::assertStringContainsString('vs 1 before', $body);
        self::assertStringContainsString('<a href="/mentions?status=pending">', $body);
        self::assertStringContainsString('<span class="stat-label">Awaiting review</span>', $body);
        self::assertStringContainsString('6 in all, against 2 in the 30 days before.', $body);
    }

    public function testQuietAccountsGetOneLineAndApprovingRefreshesTheCounts(): void
    {
        $csrf = $this->signIn($this->alice);
        $body = $this->request('GET', '/dashboard')->body;
        self::assertStringContainsString('No webmentions in the last 60 days.', $body);
        self::assertStringNotContainsString('class="stats"', $body);

        $id = $this->createLink($this->site, 'https://alice.example/post', 'https://a.example/1', ['type' => 'reply', 'verified' => 0, 'status' => 'pending']);
        $body = $this->request('GET', '/dashboard')->body;
        self::assertStringContainsString('<span class="stat-label">Awaiting review</span>', $body, 'pending shows even while the cached counts are zero');
        self::assertStringContainsString('<a href="/mentions?type=reply">' . "\n" . '                        <span class="stat-count">0</span>', $body);

        $this->request('POST', '/approve', post: ['id' => (string) $id, 'csrf' => $csrf]);
        $body = $this->request('GET', '/dashboard')->body;
        self::assertStringContainsString('<a href="/mentions?type=reply">' . "\n" . '                        <span class="stat-count">1</span>', $body, 'approving forgets the cache');
        self::assertStringNotContainsString('Awaiting review</span>', $body);

        $this->createLink($this->site, 'https://alice.example/post', 'https://a.example/2', ['type' => 'reply']);
        self::assertSame(1, $this->service(AccountOverview::class)->recent($this->alice->id)['total'], 'served from the cache for a while');
        self::assertGreaterThan(0, $this->redis->ttl('webmention:overview:' . $this->alice->id));

        $this->request('POST', '/delete', post: ['id' => (string) $id, 'csrf' => $csrf]);
        self::assertSame(1, $this->service(AccountOverview::class)->recent($this->alice->id)['total'], 'deleting forgets it: one gone, one new');
    }
}
