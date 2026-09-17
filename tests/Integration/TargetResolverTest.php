<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Model\Account;
use Webmention\Model\Site;
use Webmention\Storage\FragmentFolder;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\PageRepository;
use Webmention\Storage\SiteRepository;
use Webmention\Tests\Support\IntegrationTestCase;
use Webmention\Webmention\TargetResolver;

/**
 * Mentions are filed under the target's canonical URL (issues 217, 92, 106).
 */
final class TargetResolverTest extends IntegrationTestCase
{
    private const ENTRY = 'http://target.example.com/entry';

    private Account $account;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = $this->createAccount('target.example.com');
        $this->site    = $this->createSite($this->account, 'target.example.com', ['callback_url' => 'https://hooks.example.net/webmention']);

        // /entry-old redirects to /entry; /entry-alias declares rel=canonical /entry (fixture).
        $this->http->respond('GET', 'http://target.example.com/entry-old', 301, '', ['Location' => 'http://target.example.com/entry']);
    }

    public function testRedirectsCanonicalAndFragmentsAllFileUnderOnePage(): void
    {
        $targets = [
            'http://target.example.com/entry-old'      => 'http://source.example.org/via-redirect',
            'http://target.example.com/entry-alias'    => 'http://source.example.org/via-canonical',
            'http://target.example.com/entry#comments' => 'http://source.example.org/via-fragment',
            self::ENTRY                                => 'http://source.example.org/direct',
        ];
        foreach ($targets as $target => $source) {
            $this->http->respond('GET', $source, 200, '<div class="h-entry"><a class="u-in-reply-to" href="' . $target . '">reply</a><p class="e-content">hi</p></div>', ['Content-Type' => 'text/html']);
            $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => $source, 'target' => $target, 'debug' => '1']);
            self::assertSame(200, $response->status, "$target: {$response->body}");
        }

        $pages = $this->service(PageRepository::class);
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM pages'), 'one page for all four targets');
        $page = $pages->findBySiteAndHref($this->site->id, self::ENTRY);
        self::assertNotNull($page);
        self::assertSame('entry', $page->type);
        self::assertSame('An Entry', $page->name);
        self::assertSame(4, (int) $this->db->value('SELECT COUNT(*) FROM links WHERE page_id = ?', [$page->id]));
        self::assertEqualsCanonicalizing(
            ['http://target.example.com/entry-old', 'http://target.example.com/entry-alias'],
            array_column($pages->aliasesForPage($page->id), 'href'),
        );

        // Every fragment-less form of the URL returns all four mentions, and wm-target is canonical.
        foreach (['http://target.example.com/entry-old', 'http://target.example.com/entry-alias', self::ENTRY] as $target) {
            $jf2 = self::json($this->request('GET', '/api/mentions.jf2', ['target' => $target]));
            self::assertCount(4, $jf2['children'], $target);
            self::assertSame([self::ENTRY], array_values(array_unique(array_column($jf2['children'], 'wm-target'))), $target);
            self::assertSame(4, self::json($this->request('GET', '/api/count', ['target' => $target]))['count'], $target);
        }

        // Naming the fragment returns only what was sent to it.
        $jf2 = self::json($this->request('GET', '/api/mentions.jf2', ['target' => 'http://target.example.com/entry#comments']));
        self::assertCount(1, $jf2['children']);
        self::assertSame('http://source.example.org/via-fragment', $jf2['children'][0]['wm-source']);
        self::assertSame('comments', $jf2['children'][0]['wm-fragment']);
        self::assertSame(self::ENTRY, $jf2['children'][0]['wm-target'], 'wm-target stays canonical');
        self::assertSame(1, self::json($this->request('GET', '/api/count', ['target' => 'http://target.example.com/entry#comments']))['count']);

        // The web hook and status keep the target as the sender gave it.
        $hook = json_decode((string) $this->http->posts('https://hooks.example.net/webmention')[0]['body'], true);
        self::assertSame('http://target.example.com/entry-old', $hook['target']);
        self::assertSame(self::ENTRY, $hook['post']['wm-target']);

        // A second mention to an alias is answered from the alias table: no fetch of the target.
        $before = count($this->http->requests);
        $this->http->respond('GET', 'http://source.example.org/another', 200, '<a href="http://target.example.com/entry-old">x</a>', ['Content-Type' => 'text/html']);
        $this->request('POST', '/target.example.com/webmention', post: ['source' => 'http://source.example.org/another', 'target' => 'http://target.example.com/entry-old', 'debug' => '1']);
        $fetched = array_column(array_slice($this->http->requests, $before), 'url');
        self::assertNotContains('http://target.example.com/entry-old', $fetched);
        self::assertNotContains(self::ENTRY, $fetched);
        self::assertSame(5, (int) $this->db->value('SELECT COUNT(*) FROM links WHERE page_id = ?', [$page->id]));
    }

    public function testACanonicalOffTheAccountIsIgnored(): void
    {
        $this->http->respond('GET', 'http://target.example.com/moved-away', 301, '', ['Location' => 'http://elsewhere.example/x']);
        $this->http->respond('GET', 'http://elsewhere.example/x', 200, '<p>elsewhere</p>', ['Content-Type' => 'text/html']);
        $this->http->respond('GET', 'http://source.example.org/s', 200, '<a href="http://target.example.com/moved-away">x</a>', ['Content-Type' => 'text/html']);

        $this->request('POST', '/target.example.com/webmention', post: ['source' => 'http://source.example.org/s', 'target' => 'http://target.example.com/moved-away', 'debug' => '1']);

        self::assertNotNull($this->service(PageRepository::class)->findBySiteAndHref($this->site->id, 'http://target.example.com/moved-away'));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM page_aliases'));
    }

    public function testARedirectToAnotherSiteOnTheAccountFilesThere(): void
    {
        $www = $this->createSite($this->account, 'www.target.example.com');
        $this->http->respond('GET', 'http://target.example.com/post', 301, '', ['Location' => 'https://www.target.example.com/post']);
        $this->http->respond('GET', 'https://www.target.example.com/post', 200, '<div class="h-entry"><h1 class="p-name">Post</h1></div>', ['Content-Type' => 'text/html']);
        $this->http->respond('GET', 'http://source.example.org/s', 200, '<a href="http://target.example.com/post">x</a>', ['Content-Type' => 'text/html']);

        $this->request('POST', '/target.example.com/webmention', post: ['source' => 'http://source.example.org/s', 'target' => 'http://target.example.com/post', 'debug' => '1']);

        $page = $this->service(PageRepository::class)->findBySiteAndHref($www->id, 'https://www.target.example.com/post');
        self::assertNotNull($page);
        $link = $this->service(LinkRepository::class)->recentForAccount($this->account->id, 1)[0];
        self::assertSame($page->id, $link->pageId);
        self::assertSame($www->id, $link->siteId);
    }

    public function testACanonicalOnTheWwwTwinStaysOnTheSite(): void
    {
        // The account has only target.example.com; the page canonicalises to www.
        $this->http->respond('GET', 'http://target.example.com/moved', 301, '', ['Location' => 'https://www.target.example.com/moved']);
        $this->http->respond('GET', 'https://www.target.example.com/moved', 200, '<div class="h-entry"><h1 class="p-name">Moved</h1></div>', ['Content-Type' => 'text/html']);
        $this->http->respond('GET', 'http://source.example.org/w', 200, '<a href="http://target.example.com/moved">x</a>', ['Content-Type' => 'text/html']);

        $this->request('POST', '/target.example.com/webmention', post: ['source' => 'http://source.example.org/w', 'target' => 'http://target.example.com/moved', 'debug' => '1']);

        $page = $this->service(PageRepository::class)->findBySiteAndHref($this->site->id, 'https://www.target.example.com/moved');
        self::assertNotNull($page, 'filed under the canonical www URL, on the apex site');
        self::assertSame($page->id, $this->service(LinkRepository::class)->recentForAccount($this->account->id, 1)[0]->pageId);
    }

    public function testAnUnreachableTargetIsFiledAsGiven(): void
    {
        $this->http->respond('GET', 'http://target.example.com/down', 0, '', [], 'timeout');
        $this->http->respond('GET', 'http://source.example.org/s', 200, '<a href="http://target.example.com/down">x</a>', ['Content-Type' => 'text/html']);

        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => 'http://source.example.org/s', 'target' => 'http://target.example.com/down', 'debug' => '1']);

        self::assertSame(200, $response->status);
        self::assertNotNull($this->service(PageRepository::class)->findBySiteAndHref($this->site->id, 'http://target.example.com/down'));
    }

    public function testRemovalFindsTheMentionThroughAnAlias(): void
    {
        $this->http->respond('GET', 'http://source.example.org/s', 200, '<a href="http://target.example.com/entry-old">x</a>', ['Content-Type' => 'text/html']);
        $this->request('POST', '/target.example.com/webmention', post: ['source' => 'http://source.example.org/s', 'target' => 'http://target.example.com/entry-old', 'debug' => '1']);
        self::assertCount(1, $this->service(LinkRepository::class)->recentForAccount($this->account->id, 10));

        $this->http->respond('GET', 'http://source.example.org/s', 200, '<p>link gone</p>', ['Content-Type' => 'text/html']);
        $this->redis->flushDb();
        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => 'http://source.example.org/s', 'target' => 'http://target.example.com/entry-old', 'debug' => '1']);

        // The synchronous response reports the outcome as its error code, as the status URL does.
        self::assertSame('deleted', self::json($response)['error']);
        self::assertCount(0, $this->service(LinkRepository::class)->recentForAccount($this->account->id, 10));
    }

    public function testMovedPageCanBeReFiledFromTheSitesPage(): void
    {
        // Two mentions were filed under /old before it started redirecting to /entry, which has one of its own.
        $old = $this->service(PageRepository::class)->create($this->account->id, $this->site->id, 'http://target.example.com/old');
        $this->createLink($this->site, 'http://target.example.com/old', 'https://a.example/1');
        $this->createLink($this->site, 'http://target.example.com/old', 'https://shared.example/1');
        $this->createLink($this->site, self::ENTRY, 'https://shared.example/1');
        $this->createLink($this->site, self::ENTRY, 'https://b.example/2');
        $this->http->respond('GET', 'http://target.example.com/old', 301, '', ['Location' => self::ENTRY]);

        $csrf = $this->signIn($this->account);

        // Not redirecting: nothing happens.
        $same = $this->request('POST', '/settings/sites/merge', post: ['old_url' => self::ENTRY, 'csrf' => $csrf]);
        self::assertStringContainsString('merge_error=', (string) $same->header('location'));

        // Someone else's URL: refused.
        $mallory = $this->createAccount('mallory.example');
        $mcsrf   = $this->signIn($mallory);
        $other   = $this->request('POST', '/settings/sites/merge', post: ['old_url' => 'http://target.example.com/old', 'csrf' => $mcsrf]);
        self::assertStringContainsString(rawurlencode('not on one of your sites'), (string) $other->header('location'));
        self::assertNotNull($this->service(PageRepository::class)->find($old->id));

        $csrf     = $this->signIn($this->account);
        $response = $this->request('POST', '/settings/sites/merge', post: ['old_url' => 'http://target.example.com/old', 'csrf' => $csrf]);

        self::assertSame(303, $response->status);
        self::assertStringContainsString(rawurlencode('1 mention from http://target.example.com/old now filed under http://target.example.com/entry.'), (string) $response->header('location'));
        self::assertNull($this->service(PageRepository::class)->find($old->id));

        $entry = $this->service(PageRepository::class)->findBySiteAndHref($this->site->id, self::ENTRY);
        self::assertSame(3, (int) $this->db->value('SELECT COUNT(*) FROM links WHERE page_id = ?', [$entry->id]), 'the shared source is not duplicated');
        self::assertSame(3, self::json($this->request('GET', '/api/count', ['target' => 'http://target.example.com/old']))['count'], 'the old URL still answers, as an alias');

        $page = $this->request('GET', '/settings/sites')->body;
        self::assertStringContainsString('Moved a page?', $page);
    }

    public function testFragmentPagesAreFoldedByTheMigration(): void
    {
        $pages = $this->service(PageRepository::class);
        $this->createLink($this->site, 'http://target.example.com/post#comments', 'https://a.example/1');
        $this->createLink($this->site, 'http://target.example.com/post#top', 'https://a.example/2');
        $this->createLink($this->site, 'http://target.example.com/post', 'https://a.example/3');
        $this->createLink($this->site, 'http://target.example.com/other#x', 'https://a.example/4'); // no base page yet

        $folder = new FragmentFolder($this->db, $pages);
        self::assertCount(3, $folder->fragmentPages());

        $notes = array_map(static fn ($p): string => $folder->fold($p), $folder->fragmentPages());
        self::assertSame(3, (int) $this->db->value("SELECT COUNT(*) FROM pages WHERE href LIKE '%#%'"), 'a dry run changes nothing');
        self::assertStringContainsString('1 mentions into page #', $notes[0]);
        self::assertStringContainsString('into new page http://target.example.com/other', $notes[2]);

        foreach ($folder->fragmentPages() as $p) {
            $folder->fold($p, dryRun: false);
        }

        self::assertSame([], $folder->fragmentPages());
        $post = $pages->findBySiteAndHref($this->site->id, 'http://target.example.com/post');
        self::assertSame(3, (int) $this->db->value('SELECT COUNT(*) FROM links WHERE page_id = ?', [$post->id]));
        self::assertSame(3, self::json($this->request('GET', '/api/count', ['target' => 'http://target.example.com/post']))['count']);
        // These rows were filed before the fragment was recorded, so asking
        // for one finds nothing; tools/recover-fragments fills them in.
        self::assertSame(0, self::json($this->request('GET', '/api/count', ['target' => 'http://target.example.com/post#anything']))['count']);
        self::assertSame(1, self::json($this->request('GET', '/api/count', ['target' => 'http://target.example.com/other']))['count']);
        self::assertNotNull($pages->findByAlias($this->site->id, 'http://target.example.com/post#comments'));
    }

    public function testKeyDropsOnlyTheFragment(): void
    {
        self::assertSame('https://a.example/p', TargetResolver::key('https://a.example/p#x'));
        self::assertSame('https://a.example/p/', TargetResolver::key('https://a.example/p/'));
        self::assertSame('https://a.example/p?q=1', TargetResolver::key('https://a.example/p?q=1#frag'));
        self::assertSame('https://a.example/p', TargetResolver::key('https://a.example/p#'));
    }
}
