<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Model\Account;
use Webmention\Model\Site;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\PageRepository;
use Webmention\Storage\SiteRepository;
use Webmention\Tests\Support\IntegrationTestCase;
use Webmention\Webmention\Processor;
use Webmention\Webmention\Queue;

/**
 * Archived sites refuse new webmentions but keep the ones they have.
 */
final class SiteArchiveTest extends IntegrationTestCase
{
    private Account $alice;
    private Site $live;
    private Site $old;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = $this->createAccount('alice.example');
        $this->live  = $this->createSite($this->alice, 'alice.example', ['verified_at' => '2026-01-01 00:00:00']);
        $this->old   = $this->createSite($this->alice, 'old.example', ['verified_at' => '2020-01-01 00:00:00']);
    }

    public function testAnArchivedSiteRefusesNewWebmentionsButKeepsItsOwn(): void
    {
        $existing = $this->createLink($this->old, 'https://old.example/post', 'https://bob.example/reply');
        $token    = $this->service(AccountRepository::class)->regenerateToken($this->alice->id);
        $csrf     = $this->signIn($this->alice);

        $response = $this->request('POST', '/settings/sites/archive', post: ['site_id' => [(string) $this->old->id], 'csrf' => $csrf]);
        self::assertSame(303, $response->status);
        self::assertSame('/settings/sites', $response->header('location'));
        self::assertStringContainsString('<p class="notice">Archived 1 site.</p>', $this->request('GET', '/settings/sites')->body);
        self::assertTrue($this->service(SiteRepository::class)->find($this->old->id)?->isArchived());

        // Both endpoints refuse it.
        $refused = $this->request('POST', '/alice.example/webmention', post: ['source' => 'https://carol.example/new', 'target' => 'https://old.example/post']);
        self::assertSame(404, $refused->status);
        self::assertSame(['error' => 'invalid_target', 'error_description' => 'target domain is archived on this account'], self::json($refused));
        $refused = $this->request('POST', '/d/old.example/webmention', post: ['source' => 'https://carol.example/other', 'target' => 'https://old.example/post']);
        self::assertSame(404, $refused->status);
        self::assertSame('target domain is archived on this account', self::json($refused)['error_description']);

        // The live site on the same account still receives.
        self::assertSame(201, $this->request('POST', '/alice.example/webmention', post: ['source' => 'https://carol.example/live', 'target' => 'https://alice.example/post'])->status);

        // What it already had is still public, in token queries and in the export.
        self::assertSame([$existing], array_column(self::json($this->request('GET', '/api/mentions.jf2', ['target' => 'https://old.example/post']))['children'], 'wm-id'));
        self::assertSame([$existing], array_column(self::json($this->request('GET', '/api/mentions.jf2', ['token' => $token, 'domain' => 'old.example']))['children'], 'wm-id'));
        $export = $this->request('GET', '/api/export.jf2', ['token' => $token, 'domain' => 'old.example']);
        self::assertSame(200, $export->status);
        self::assertSame([$existing], array_column(json_decode($export->capture(), true)['children'], 'wm-id'));

        // Unarchiving brings receiving back.
        $response = $this->request('POST', '/settings/sites/unarchive', post: ['site_id' => (string) $this->old->id, 'csrf' => $csrf]);
        self::assertSame("/settings/sites/{$this->old->id}", $response->header('location'));
        self::assertStringContainsString('Unarchived. This site accepts webmentions again.', $this->request('GET', "/settings/sites/{$this->old->id}")->body);
        self::assertFalse($this->service(SiteRepository::class)->find($this->old->id)?->isArchived());
        self::assertSame(201, $this->request('POST', '/alice.example/webmention', post: ['source' => 'https://carol.example/new', 'target' => 'https://old.example/post'])->status);
    }

    public function testAJobQueuedBeforeTheArchiveIsRefusedByTheWorker(): void
    {
        $response = $this->request('POST', '/alice.example/webmention', post: ['source' => 'http://source.example.org/like-of', 'target' => 'https://old.example/entry']);
        self::assertSame(201, $response->status);
        $statusPath = (string) parse_url(self::json($response)['location'], PHP_URL_PATH);

        $this->service(SiteRepository::class)->archive($this->alice->id, [$this->old->id]);

        $job = $this->service(Queue::class)->pop(1);
        self::assertNotNull($job);
        self::assertSame('invalid_target', $this->service(Processor::class)->process($job));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM links'));

        $status = self::json($this->request('GET', $statusPath));
        self::assertSame('invalid_target', $status['status']);
        self::assertSame('target domain is archived on this account', $status['summary']);
    }

    public function testTheSitesListSeparatesArchivedSitesAndOnlyArchivesYourOwn(): void
    {
        $mallory   = $this->createAccount('mallory.example');
        $hers      = $this->createSite($mallory, 'mallory.example');
        $this->createLink($this->live, 'https://alice.example/post', 'https://bob.example/reply', ['created_at' => '2026-03-04 10:00:00']);
        $csrf = $this->signIn($this->alice);

        $page = $this->request('GET', '/settings/sites')->body;
        self::assertStringContainsString('name="site_id[]" value="' . $this->old->id . '"', $page);
        self::assertStringContainsString('Archive selected</button>', $page);
        self::assertStringContainsString("You can export your site's webmentions after it is archived.", $page);
        self::assertStringContainsString('Mar 4, 2026', $page, 'the last webmention date');
        self::assertStringContainsString('<span class="muted">never</span>', $page, 'old.example has none');
        self::assertStringNotContainsString('id="archived"', $page);

        // Another account's id in the form is ignored.
        $response = $this->request('POST', '/settings/sites/archive', post: ['site_id' => [(string) $this->old->id, (string) $hers->id], 'csrf' => $csrf]);
        self::assertSame('/settings/sites', $response->header('location'));
        self::assertFalse($this->service(SiteRepository::class)->find($hers->id)?->isArchived());

        $page = $this->request('GET', '/settings/sites')->body;
        self::assertStringContainsString('<p class="notice">Archived 1 site.</p>', $page);
        self::assertStringNotContainsString('<p class="notice">', $this->request('GET', '/settings/sites', ['notice' => 'Archived 99 sites.'])->body, 'not from the URL, and only once');
        $archivedCard = substr($page, (int) strpos($page, 'id="archived"'));
        self::assertStringContainsString('old.example', $archivedCard);
        self::assertStringNotContainsString('name="site_id[]" value="' . $this->old->id . '"', $page, 'no longer in the live table');

        // Nothing selected.
        self::assertSame('/settings/sites', $this->request('POST', '/settings/sites/archive', post: ['csrf' => $csrf])->header('location'));
        self::assertStringContainsString('No sites were archived.', $this->request('GET', '/settings/sites')->body);

        // From a site's own page, back to that page.
        $response = $this->request('POST', '/settings/sites/archive', post: ['site_id' => [(string) $this->live->id], 'back' => 'site', 'csrf' => $csrf]);
        self::assertSame("/settings/sites/{$this->live->id}", $response->header('location'));
        $sitePage = $this->request('GET', "/settings/sites/{$this->live->id}")->body;
        self::assertStringContainsString('Archived. This site no longer accepts webmentions.', $sitePage);
        self::assertStringContainsString('<h2>Archived</h2>', $sitePage);
        self::assertStringContainsString('action="/settings/sites/unarchive"', $sitePage);
        $token = (string) $this->service(AccountRepository::class)->find($this->alice->id)?->token;
        self::assertStringContainsString('href="https://webmention.io/api/export.jf2?token=' . rawurlencode($token) . '&amp;domain=alice.example" download>Download export</a>', $sitePage);
        self::assertStringNotContainsString('<h2>Verification</h2>', $sitePage);
        self::assertStringNotContainsString('action="/webhook/configure"', $sitePage);

        // Everything archived: the list says so.
        self::assertStringContainsString('Every site on this account is archived.', $this->request('GET', '/settings/sites')->body);
    }

    public function testArchivedSitesAreSkippedByRechecksAndAreNoCanonicalDestination(): void
    {
        $dormant = $this->createSite($this->alice, 'dormant.example');
        $repo    = $this->service(SiteRepository::class);
        $repo->archive($this->alice->id, [$this->old->id, $dormant->id]);

        self::assertNotContains($dormant->id, array_map(static fn (Site $s): int => $s->id, $repo->unverifiedToCheck(100)));
        $rechecked = array_map(static fn (Site $s): int => $s->id, $repo->verifiedToRecheck(0, 100));
        self::assertContains($this->live->id, $rechecked);
        self::assertNotContains($this->old->id, $rechecked);

        // alice.example/post redirects to the archived old.example: the mention stays on alice.example.
        $this->http->respond('GET', 'https://alice.example/post', 301, '', ['Location' => 'https://old.example/post']);
        $this->http->respond('GET', 'https://old.example/post', 200, '<div class="h-entry"><h1 class="p-name">Post</h1></div>', ['Content-Type' => 'text/html']);
        $this->http->respond('GET', 'http://source.example.org/arch', 200, '<a href="https://alice.example/post">x</a>', ['Content-Type' => 'text/html']);

        $this->request('POST', '/alice.example/webmention', post: ['source' => 'http://source.example.org/arch', 'target' => 'https://alice.example/post', 'debug' => '1']);

        $links = $this->service(LinkRepository::class)->recentForAccount($this->alice->id, 5);
        self::assertCount(1, $links);
        self::assertSame($this->live->id, $links[0]->siteId);
        self::assertNotNull($this->service(PageRepository::class)->findBySiteAndHref($this->live->id, 'https://alice.example/post'));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM pages WHERE site_id = ?', [$this->old->id]));
    }

    public function testReAddingAnArchivedDomainLeadsToItsPage(): void
    {
        $this->service(SiteRepository::class)->archive($this->alice->id, [$this->old->id]);
        $csrf = $this->signIn($this->alice);

        $response = $this->request('POST', '/settings/sites/new', post: ['domain' => 'old.example', 'csrf' => $csrf]);

        self::assertSame(303, $response->status);
        self::assertSame("/settings/sites/{$this->old->id}", $response->header('location'));
        self::assertSame([], $this->http->requests, 'nothing is fetched');
        self::assertStringContainsString('already on your account, archived', $this->request('GET', "/settings/sites/{$this->old->id}")->body);
    }
}
