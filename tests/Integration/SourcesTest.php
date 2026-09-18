<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Model\Account;
use Webmention\Model\Site;
use Webmention\Storage\BlockRepository;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\MuteRepository;
use Webmention\Tests\Support\IntegrationTestCase;
use Webmention\Webmention\SourceActivity;

/**
 * The Sources page: who sent what lately, and blocking or muting from there.
 */
final class SourcesTest extends IntegrationTestCase
{
    private Account $alice;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = $this->createAccount('alice.example');
        $this->site  = $this->createSite($this->alice, 'alice.example');
    }

    public function testDomainsOfTheLastThirtyDaysWithCountsAndState(): void
    {
        $now = static fn (string $ago): string => date('Y-m-d H:i:s', strtotime($ago));
        for ($i = 1; $i <= 3; $i++) {
            $this->createLink($this->site, 'https://alice.example/post', "https://busy.example/$i", ['created_at' => $now("-$i days")]);
        }
        $this->createLink($this->site, 'https://alice.example/post', 'https://busy.example/held', ['verified' => 0, 'status' => 'pending', 'created_at' => $now('-2 hours')]);
        $this->createLink($this->site, 'https://alice.example/post', 'https://busy.example/gone', ['deleted' => 1, 'created_at' => $now('-3 hours')]);
        $this->createLink($this->site, 'https://alice.example/post', 'https://quiet.example/1', ['created_at' => $now('-10 days')]);
        $this->createLink($this->site, 'https://alice.example/post', 'https://muted.example/1', ['verified' => 0, 'status' => 'hidden', 'created_at' => $now('-1 day')]);
        $this->createLink($this->site, 'https://alice.example/post', 'https://blocked.example/1', ['deleted' => 1, 'created_at' => $now('-1 day')]);
        $this->createLink($this->site, 'https://alice.example/post', 'https://old.example/1', ['created_at' => $now('-40 days')]);
        $mallory = $this->createAccount('mallory.example');
        $this->createLink($this->createSite($mallory, 'mallory.example'), 'https://mallory.example/x', 'https://theirs.example/1');
        $this->service(MuteRepository::class)->add($this->alice->id, 'source', 'muted.example');
        $this->service(BlockRepository::class)->blockDomain($this->alice->id, 'blocked.example');
        $this->signIn($this->alice);

        $page = $this->request('GET', '/sources');
        self::assertSame(200, $page->status);
        $body = $page->body;

        self::assertStringNotContainsString('old.example', $body, 'outside the window');
        self::assertStringNotContainsString('theirs.example', $body, 'another account');
        self::assertLessThan(strpos($body, 'quiet.example'), strpos($body, 'busy.example'), 'busiest first');

        $busy = substr($body, (int) strpos($body, 'href="/mentions?domain=busy.example">busy.example</a>'));
        $busy = substr($busy, 0, strpos($busy, '</tr>'));
        self::assertStringContainsString('<td class="num">5</td>', $busy, 'published, pending and deleted all count');
        self::assertStringContainsString('<a href="/mentions?status=pending&amp;domain=busy.example">1</a>', $busy);
        self::assertStringContainsString('<td class="num">1</td>', $busy, 'deleted');
        self::assertStringContainsString(date('M j, Y', strtotime('-2 hours')), $busy, 'last seen');
        self::assertStringContainsString('action="/mute"', $busy);
        self::assertStringContainsString('href="/delete?domain=busy.example&amp;back=%2Fsources"', $busy);

        $muted = substr($body, (int) strpos($body, '>muted.example</a>'));
        $muted = substr($muted, 0, strpos($muted, '</tr>'));
        self::assertStringContainsString('title="Source on muted.example">Muted</span>', $muted);
        self::assertStringNotContainsString('action="/mute"', $muted);
        self::assertStringContainsString('Block…', $muted);

        $blocked = substr($body, (int) strpos($body, '>blocked.example</a>'));
        $blocked = substr($blocked, 0, strpos($blocked, '</tr>'));
        self::assertStringContainsString('>Blocked</span>', $blocked);
        self::assertStringNotContainsString('action="/mute"', $blocked);
        self::assertStringNotContainsString('Block…', $blocked);
    }

    public function testMutingFromThePageHidesAndComesBackToIt(): void
    {
        $id   = $this->createLink($this->site, 'https://alice.example/post', 'https://noisy.example/1');
        $csrf = $this->signIn($this->alice);
        $this->request('GET', '/sources');

        $response = $this->request('POST', '/mute', post: ['kind' => 'source', 'pattern' => 'noisy.example', 'back' => '/sources', 'csrf' => $csrf]);
        self::assertSame(303, $response->status);
        self::assertSame('/sources?notice=' . rawurlencode('Muted source on noisy.example. 1 existing webmention hidden; new ones will be too.'), $response->header('location'));
        self::assertSame('hidden', $this->service(LinkRepository::class)->find($id)?->status);

        $body = $this->request('GET', '/sources', ['notice' => 'Muted source on noisy.example.'])->body;
        self::assertStringContainsString('<p class="notice">Muted source on noisy.example.</p>', $body);
        self::assertStringContainsString('>Muted</span>', $body);
        self::assertStringNotContainsString('action="/mute"', $body);
    }

    public function testBlockingFromThePageGoesThroughTheConfirmationAndBack(): void
    {
        $ids = [
            $this->createLink($this->site, 'https://alice.example/post', 'https://spam.example/1'),
            $this->createLink($this->site, 'https://alice.example/post', 'https://spam.example/2'),
        ];
        $csrf = $this->signIn($this->alice);

        $confirm = $this->request('GET', '/delete', ['domain' => 'spam.example', 'back' => '/sources']);
        self::assertSame(200, $confirm->status);
        self::assertStringNotContainsString('Nothing to delete', $confirm->body);
        self::assertStringContainsString('<h2>Block this domain</h2>', $confirm->body);
        self::assertStringContainsString('received 2 webmentions from this domain', $confirm->body);
        self::assertStringContainsString('name="back" value="/sources"', $confirm->body);
        self::assertStringContainsString('href="/sources">Cancel</a>', $confirm->body);
        self::assertStringNotContainsString('<h2>Delete this webmention</h2>', $confirm->body);

        $response = $this->request('POST', '/delete', post: ['domain' => 'spam.example', 'back' => '/sources', 'csrf' => $csrf]);
        self::assertSame('/sources?notice=' . rawurlencode('Blocked spam.example and deleted every webmention from it.'), $response->header('location'));
        foreach ($ids as $id) {
            self::assertTrue($this->service(LinkRepository::class)->find($id)?->deleted);
        }
        self::assertTrue($this->service(BlockRepository::class)->isDomainBlocked($this->alice->id, 'spam.example'));

        $body = $this->request('GET', '/sources')->body;
        self::assertStringContainsString('>Blocked</span>', $body);
        self::assertStringContainsString('<td class="num">2</td>', $body, 'the deleted column, refreshed at once');

        // Asking again offers nothing to do.
        $again = $this->request('GET', '/delete', ['domain' => 'spam.example', 'back' => '/sources'])->body;
        self::assertStringContainsString('Domain already blocked', $again);
        self::assertStringNotContainsString('Block and delete', $again);

        // The dashboard's own delete still lands on the dashboard.
        $other = $this->createLink($this->site, 'https://alice.example/post', 'https://other.example/1');
        $response = $this->request('POST', '/delete', post: ['id' => (string) $other, 'csrf' => $csrf]);
        self::assertSame('/dashboard', $response->header('location'));
    }

    public function testCountsAreCachedBrieflyAndForgottenOnBlockOrMute(): void
    {
        $this->createLink($this->site, 'https://alice.example/post', 'https://a.example/1');
        $csrf = $this->signIn($this->alice);

        self::assertStringContainsString('a.example', $this->request('GET', '/sources')->body);
        $this->createLink($this->site, 'https://alice.example/post', 'https://b.example/1');
        self::assertStringNotContainsString('b.example', $this->request('GET', '/sources')->body, 'served from the cache');
        self::assertGreaterThan(0, $this->redis->ttl('webmention:sources:' . $this->alice->id));

        $this->request('POST', '/mute', post: ['kind' => 'source', 'pattern' => 'c.example', 'back' => '/sources', 'csrf' => $csrf]);
        self::assertStringContainsString('b.example', $this->request('GET', '/sources')->body, 'a mute forgets the cache');

        $this->createLink($this->site, 'https://alice.example/post', 'https://d.example/1');
        $this->request('POST', '/delete', post: ['domain' => 'a.example', 'csrf' => $csrf]);
        self::assertStringContainsString('d.example', $this->request('GET', '/sources')->body, 'a block forgets the cache');

        $this->service(SourceActivity::class)->forget($this->alice->id);
        self::assertSame(-2, $this->redis->ttl('webmention:sources:' . $this->alice->id));
    }

    public function testEmptyAndSignedOut(): void
    {
        self::assertSame(302, $this->request('GET', '/sources')->status);
        $this->signIn($this->alice);
        self::assertStringContainsString('Nothing has arrived in the last 30 days.', $this->request('GET', '/sources')->body);
    }
}
