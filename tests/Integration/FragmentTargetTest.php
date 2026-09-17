<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Model\Account;
use Webmention\Model\Site;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\PageRepository;
use Webmention\Tests\Support\IntegrationTestCase;
use Webmention\Webmention\TargetResolver;

/**
 * A target's #fragment is recorded, so a page addressed only by fragment
 * (one per image in a gallery, say) can be queried a fragment at a time.
 */
final class FragmentTargetTest extends IntegrationTestCase
{
    private const GALLERY = 'http://target.example.com/gallery';

    private Account $account;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = $this->createAccount('target.example.com');
        $this->site    = $this->createSite($this->account, 'target.example.com');
        $this->http->respond('GET', self::GALLERY, 200, '<div class="h-entry"><h1 class="p-name">Gallery</h1></div>', ['Content-Type' => 'text/html']);
    }

    public function testEachFragmentKeepsItsOwnWebmention(): void
    {
        $this->like('http://source.example.org/liker', self::GALLERY . '#photo-1');
        $this->like('http://source.example.org/liker', self::GALLERY . '#photo-2');

        // One page, two webmentions from the same source, one per fragment.
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM pages'));
        $page = $this->service(PageRepository::class)->findBySiteAndHref($this->site->id, self::GALLERY);
        self::assertNotNull($page);
        self::assertSame(['photo-1', 'photo-2'], array_column($this->db->all('SELECT target_fragment FROM links WHERE page_id = ? ORDER BY id', [$page->id]), 'target_fragment'));

        // Sending the same one again updates it instead of adding another.
        $this->redis->flushDb();
        $this->like('http://source.example.org/liker', self::GALLERY . '#photo-1');
        self::assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM links'));
    }

    public function testQueriesAnswerPerFragmentOrAsAWhole(): void
    {
        $this->like('http://source.example.org/one', self::GALLERY . '#photo-1');
        $this->like('http://source.example.org/two', self::GALLERY . '#photo-1');
        $this->like('http://source.example.org/three', self::GALLERY . '#photo-2');
        $this->like('http://source.example.org/four', self::GALLERY);

        // The whole page.
        $all = self::json($this->request('GET', '/api/mentions.jf2', ['target' => self::GALLERY]));
        self::assertCount(4, $all['children']);
        self::assertSame(4, $all['paging']['total']);
        self::assertSame(4, self::json($this->request('GET', '/api/count', ['target' => self::GALLERY]))['count']);

        // One fragment at a time.
        $one = self::json($this->request('GET', '/api/mentions.jf2', ['target' => self::GALLERY . '#photo-1']));
        self::assertSame(2, $one['paging']['total']);
        self::assertSame(['photo-1', 'photo-1'], array_column($one['children'], 'wm-fragment'));
        self::assertSame([self::GALLERY], array_values(array_unique(array_column($one['children'], 'wm-target'))), 'wm-target stays canonical');
        self::assertSame(2, self::json($this->request('GET', '/api/count', ['target' => self::GALLERY . '#photo-1']))['count']);
        self::assertSame(1, self::json($this->request('GET', '/api/count', ['target' => self::GALLERY . '#photo-2']))['count']);
        self::assertSame(0, self::json($this->request('GET', '/api/count', ['target' => self::GALLERY . '#photo-9']))['count']);

        // Several fragments at once, and the entry with no fragment has no wm-fragment.
        $two = self::json($this->request('GET', '/api/mentions.jf2', ['target' => [self::GALLERY . '#photo-1', self::GALLERY . '#photo-2']]));
        self::assertSame(3, $two['paging']['total']);
        $plain = self::json($this->request('GET', '/api/mentions.jf2', ['target' => self::GALLERY, 'wm-property' => 'like-of']));
        $noFragment = array_values(array_filter($plain['children'], static fn (array $e): bool => ($e['wm-source'] ?? '') === 'http://source.example.org/four'));
        self::assertArrayNotHasKey('wm-fragment', $noFragment[0]);

        // Mixing a fragment-less target in means "everything on that page".
        $mixed = self::json($this->request('GET', '/api/mentions.jf2', ['target' => [self::GALLERY . '#photo-1', self::GALLERY]]));
        self::assertSame(4, $mixed['paging']['total']);
    }

    public function testRowsWithNoRecordedFragmentAnswerOnlyTheWholePage(): void
    {
        // As the migration left them: filed under the page, fragment unknown.
        $this->createLink($this->site, self::GALLERY, 'https://old.example/1');

        self::assertSame(1, self::json($this->request('GET', '/api/count', ['target' => self::GALLERY]))['count']);
        self::assertSame(0, self::json($this->request('GET', '/api/count', ['target' => self::GALLERY . '#photo-1']))['count']);
        self::assertSame(1, self::json($this->request('GET', '/api/mentions.jf2', ['target' => self::GALLERY]))['paging']['total']);
        self::assertSame(0, self::json($this->request('GET', '/api/mentions.jf2', ['target' => self::GALLERY . '#photo-1']))['paging']['total']);
    }

    public function testDeletingOneFragmentLeavesTheOthers(): void
    {
        $this->like('http://source.example.org/liker', self::GALLERY . '#photo-1');
        $this->like('http://source.example.org/liker', self::GALLERY . '#photo-2');

        // The source stops linking to #photo-1: only that one goes.
        $this->redis->flushDb();
        $this->http->respond('GET', 'http://source.example.org/liker', 410, '', ['Content-Type' => 'text/html']);
        $this->request('POST', '/target.example.com/webmention', post: ['source' => 'http://source.example.org/liker', 'target' => self::GALLERY . '#photo-1', 'debug' => '1']);

        $rows = $this->db->all('SELECT target_fragment, deleted FROM links ORDER BY id');
        self::assertSame([['target_fragment' => 'photo-1', 'deleted' => 1], ['target_fragment' => 'photo-2', 'deleted' => 0]], array_map(static fn (array $r): array => ['target_fragment' => $r['target_fragment'], 'deleted' => (int) $r['deleted']], $rows));
        self::assertSame(1, self::json($this->request('GET', '/api/count', ['target' => self::GALLERY]))['count']);
    }

    public function testFragmentHelper(): void
    {
        self::assertNull(TargetResolver::fragment('https://a.example/p'));
        self::assertNull(TargetResolver::fragment('https://a.example/p#'));
        self::assertSame('x', TargetResolver::fragment('https://a.example/p#x'));
        self::assertSame('a#b', TargetResolver::fragment('https://a.example/p#a#b'));
        self::assertSame(255, strlen((string) TargetResolver::fragment('https://a.example/p#' . str_repeat('y', 300))));
    }

    private function like(string $source, string $target): void
    {
        $this->http->respond('GET', $source, 200, '<div class="h-entry"><a class="u-like-of" href="' . $target . '">like</a></div>', ['Content-Type' => 'text/html']);
        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => $source, 'target' => $target, 'debug' => '1']);
        self::assertSame(200, $response->status, "$target: {$response->body}");
    }
}
