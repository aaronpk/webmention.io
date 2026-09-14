<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Tests\Support\IntegrationTestCase;

/**
 * /api/example/mentions.jf2: sample data covering every shape of the real feed (issue 77).
 */
final class ExampleApiTest extends IntegrationTestCase
{
    public function testCoversEveryPropertyAndShape(): void
    {
        $feed = self::json($this->request('GET', '/api/example/mentions.jf2', ['per-page' => '100']));
        $children = $feed['children'];

        self::assertSame('feed', $feed['type']);
        self::assertGreaterThanOrEqual(20, count($children));
        self::assertEqualsCanonicalizing(
            ['in-reply-to', 'like-of', 'repost-of', 'bookmark-of', 'mention-of', 'rsvp'],
            array_values(array_unique(array_column($children, 'wm-property'))),
        );
        self::assertEqualsCanonicalizing(['yes', 'no', 'maybe', 'interested'], array_values(array_filter(array_column($children, 'rsvp'))));

        $has = static fn (callable $test): bool => array_filter($children, $test) !== [];
        self::assertTrue($has(static fn (array $e): bool => isset($e['content']['html']) && isset($e['content']['text'])), 'html + text content');
        self::assertTrue($has(static fn (array $e): bool => isset($e['content']['text']) && !isset($e['content']['html'])), 'text-only content');
        self::assertTrue($has(static fn (array $e): bool => !isset($e['content'])), 'no content');
        self::assertTrue($has(static fn (array $e): bool => isset($e['content']['content-type'], $e['content']['value'])), 'legacy content shape');
        self::assertTrue($has(static fn (array $e): bool => isset($e['photo']) && is_array($e['photo']) && count($e['photo']) === 2), 'photo list');
        self::assertTrue($has(static fn (array $e): bool => isset($e['photo']) && is_string($e['photo'])), 'photo as a string');
        self::assertTrue($has(static fn (array $e): bool => isset($e['video'])), 'video');
        self::assertTrue($has(static fn (array $e): bool => isset($e['audio'])), 'audio');
        self::assertTrue($has(static fn (array $e): bool => isset($e['summary']['value'])), 'summary');
        self::assertTrue($has(static fn (array $e): bool => isset($e['syndication']) && count($e['syndication']) === 2), 'syndication');
        self::assertTrue($has(static fn (array $e): bool => isset($e['swarm-coins'])), 'swarm-coins');
        self::assertTrue($has(static fn (array $e): bool => isset($e['rels']['canonical'])), 'rels.canonical');
        self::assertTrue($has(static fn (array $e): bool => $e['published'] === null && $e['published_ts'] === null), 'no published date');
        self::assertTrue($has(static fn (array $e): bool => is_string($e['published']) && str_ends_with($e['published'], '-07:00')), 'published with offset');
        self::assertTrue($has(static fn (array $e): bool => $e['wm-private'] === true), 'private');
        self::assertTrue($has(static fn (array $e): bool => $e['wm-protocol'] === 'pingback'), 'pingback');
        self::assertTrue($has(static fn (array $e): bool => $e['wm-protocol'] === null), 'null protocol');
        self::assertTrue($has(static fn (array $e): bool => $e['author']['name'] === '' && $e['author']['photo'] === ''), 'empty author');
        self::assertTrue($has(static fn (array $e): bool => $e['author']['name'] === null), 'null author');
        self::assertTrue($has(static fn (array $e): bool => $e['url'] !== $e['wm-source']), 'url differs from wm-source');
        self::assertTrue($has(static fn (array $e): bool => preg_match('/\p{So}/u', (string) $e['author']['name']) === 1), 'emoji in a name');
        self::assertTrue($has(static fn (array $e): bool => str_contains((string) ($e['content']['html'] ?? ''), '<blockquote>') && str_contains((string) ($e['content']['html'] ?? ''), '日本語')), 'long html with other scripts');

        // Every entry carries the keys a client can rely on, and ids are stable per case.
        foreach ($children as $entry) {
            foreach (['type', 'author', 'url', 'published', 'published_ts', 'wm-received', 'wm-id', 'wm-source', 'wm-target', 'wm-protocol', 'wm-property', 'wm-private'] as $key) {
                self::assertArrayHasKey($key, $entry, "wm-id {$entry['wm-id']}");
            }
            self::assertSame('https://example.com/post', $entry['wm-target']);
        }
        $ids = array_map('intval', array_column($children, 'wm-id'));
        sort($ids);
        self::assertSame(range(1001, 1021), $ids);
    }

    public function testNoRealHostsAppearAnywhere(): void
    {
        $body = $this->request('GET', '/api/example/mentions.jf2', ['per-page' => '100'])->body;
        preg_match_all('#https?://([^/"\\\\\s]+)#', $body, $m);

        $hosts = array_unique($m[1]);
        self::assertNotEmpty($hosts);
        foreach ($hosts as $host) {
            self::assertTrue(
                str_ends_with($host, '.example') || $host === 'example.com' || $host === 'webmention.io',
                "Unexpected host $host",
            );
        }

        // The only URLs on this site are the placeholder images.
        preg_match_all('#https://webmention\.io(/[^"\\\\\s]*)#', $body, $m);
        foreach (array_unique($m[1]) as $path) {
            self::assertStringStartsWith('/img/example/', $path);
            self::assertFileExists(dirname(__DIR__, 2) . '/public' . $path);
        }
    }

    public function testTargetIsEchoedOnlyWhenItIsAUrl(): void
    {
        $echoed = self::json($this->request('GET', '/api/example/mentions.jf2', ['target' => 'https://mysite.example/a-post']));
        self::assertSame(['https://mysite.example/a-post'], array_values(array_unique(array_column($echoed['children'], 'wm-target'))));

        $ignored = self::json($this->request('GET', '/api/example/mentions.jf2', ['target' => 'javascript:alert(1)']));
        self::assertSame(['https://example.com/post'], array_values(array_unique(array_column($ignored['children'], 'wm-target'))));
    }

    public function testFilteringSortingPagingSeedAndJsonp(): void
    {
        $likes = self::json($this->request('GET', '/api/example/mentions.jf2', ['wm-property' => 'like-of']));
        self::assertSame(['like-of'], array_values(array_unique(array_column($likes['children'], 'wm-property'))));

        $rsvps = self::json($this->request('GET', '/api/example/mentions.jf2', ['wm-property' => ['rsvp', 'bookmark-of']]));
        self::assertEqualsCanonicalizing(['rsvp', 'rsvp', 'rsvp', 'rsvp', 'bookmark-of'], array_column($rsvps['children'], 'wm-property'));

        $mentions = self::json($this->request('GET', '/api/example/mentions.jf2', ['wm-property' => 'mention-of', 'per-page' => '100']));
        self::assertContains(1007, array_map('intval', array_column($mentions['children'], 'wm-id')), 'the untyped legacy row is a mention');
        self::assertContains(1013, array_map('intval', array_column($mentions['children'], 'wm-id')), 'the invite is a mention');

        $all  = self::json($this->request('GET', '/api/example/mentions.jf2', ['per-page' => '100']))['children'];
        $down = array_column($all, 'wm-received');
        $up   = array_column(self::json($this->request('GET', '/api/example/mentions.jf2', ['per-page' => '100', 'sort-dir' => 'up']))['children'], 'wm-received');
        self::assertSame(array_reverse($down), $up);
        self::assertSame($down, (static function (array $d): array { rsort($d); return $d; })($down), 'newest first by default');

        self::assertCount(20, self::json($this->request('GET', '/api/example/mentions.jf2'))['children']);
        $page1 = self::json($this->request('GET', '/api/example/mentions.jf2', ['per-page' => '5', 'page' => '1']))['children'];
        self::assertSame(array_slice(array_column($all, 'wm-id'), 5, 5), array_column($page1, 'wm-id'));

        $a = $this->request('GET', '/api/example/mentions.jf2', ['seed' => '7'])->body;
        $b = $this->request('GET', '/api/example/mentions.jf2', ['seed' => '7'])->body;
        $c = $this->request('GET', '/api/example/mentions.jf2', ['seed' => '8'])->body;
        self::assertSame($a, $b);
        self::assertNotSame($a, $c);

        $jsonp = $this->request('GET', '/api/example/mentions.jf2', ['jsonp' => 'cb', 'per-page' => '1']);
        self::assertStringStartsWith('cb({', $jsonp->body);
        self::assertSame('*', $jsonp->header('access-control-allow-origin'));
        self::assertSame('no-store', $jsonp->header('cache-control'));
    }

    public function testExampleCountMatchesTheFeed(): void
    {
        $count = self::json($this->request('GET', '/api/example/count'));
        $feed  = self::json($this->request('GET', '/api/example/mentions.jf2', ['per-page' => '100']));

        self::assertSame(count($feed['children']), $count['count']);
        self::assertSame($count['count'], array_sum($count['type']));
        self::assertSame($count['count'], $feed['paging']['total']);
        self::assertArrayHasKey('mention', $count['type']);
        self::assertArrayHasKey('rsvp-yes', $count['type']);
        self::assertArrayNotHasKey('link', $count['type']);
    }
}
