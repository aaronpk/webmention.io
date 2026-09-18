<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Logging\Log;
use Webmention\Model\Account;
use Webmention\Model\Site;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\PageRepository;
use Webmention\Storage\SiteRepository;
use Webmention\Tests\Support\IntegrationTestCase;
use Webmention\Webmention\SiteDeleter;
use Webmention\Webmention\WebhookRetries;

/**
 * Deleting a site removes it and everything it received.
 */
final class SiteDeleteTest extends IntegrationTestCase
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

    public function testConfirmationPageShowsWhatGoesAndRequiresTheDomain(): void
    {
        $this->createLink($this->old, 'https://old.example/a', 'https://bob.example/1');
        $this->createLink($this->old, 'https://old.example/b', 'https://bob.example/2');
        $csrf = $this->signIn($this->alice);

        $page = $this->request('GET', "/settings/sites/{$this->old->id}/delete");
        self::assertSame(200, $page->status);
        self::assertStringContainsString('<h2>Delete old.example?</h2>', $page->body);
        self::assertStringContainsString('<dt>Pages</dt><dd>2</dd>', $page->body);
        self::assertStringContainsString('<dt>Webmentions</dt><dd>2</dd>', $page->body);
        $token = (string) $this->service(AccountRepository::class)->find($this->alice->id)?->token;
        self::assertStringContainsString('/api/export.jf2?token=' . rawurlencode($token) . '&amp;domain=old.example', $page->body);
        self::assertStringNotContainsString('domain you sign in with', $page->body);
        self::assertStringContainsString('domain you sign in with', $this->request('GET', "/settings/sites/{$this->live->id}/delete")->body);

        // A wrong or missing domain changes nothing.
        foreach (['', 'alice.example', 'old.example.evil'] as $typed) {
            $response = $this->request('POST', '/settings/sites/delete', post: ['site_id' => (string) $this->old->id, 'confirm_domain' => $typed, 'csrf' => $csrf]);
            self::assertStringStartsWith("/settings/sites/{$this->old->id}/delete?error=", (string) $response->header('location'), $typed);
        }
        self::assertNotNull($this->service(SiteRepository::class)->find($this->old->id));
        self::assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM links WHERE site_id = ?', [$this->old->id]));
    }

    public function testASmallSiteIsGoneAtOnceWithEverythingItReceived(): void
    {
        $mallory = $this->createAccount('mallory.example');
        $theirs  = $this->createSite($mallory, 'old.example');
        $theirLink = $this->createLink($theirs, 'https://old.example/a', 'https://eve.example/1');

        $a = $this->createLink($this->old, 'https://old.example/a', 'https://bob.example/1');
        $this->createLink($this->old, 'https://old.example/b', 'https://bob.example/2', ['deleted' => 1]);
        $this->createLink($this->old, 'https://old.example/b', 'https://bob.example/3', ['verified' => 0, 'status' => 'pending']);
        $keep = $this->createLink($this->live, 'https://alice.example/post', 'https://bob.example/4');
        $page = $this->service(PageRepository::class)->findBySiteAndHref($this->old->id, 'https://old.example/a');
        $this->service(PageRepository::class)->addAlias($this->old->id, 'https://old.example/a-old', (int) $page?->id);
        $this->db->insert('blocklists', ['site_id' => $this->old->id, 'source' => 'https://spam.example/', 'created_at' => '2026-01-01 00:00:00']);
        $this->db->insert('webhook_deliveries', ['site_id' => $this->old->id, 'kind' => 'mention', 'url' => 'https://hook.example/', 'request_body' => '{}', 'created_at' => '2026-01-01 00:00:00']);
        $retries = $this->service(WebhookRetries::class);
        $retries->schedule(['site_id' => $this->old->id, 'delivery_id' => 1, 'link_id' => $a, 'kind' => 'mention', 'url' => 'https://hook.example/', 'attempt' => 1, 'body' => '{}'], time() + 60);
        $retries->schedule(['site_id' => $this->live->id, 'delivery_id' => 2, 'link_id' => $keep, 'kind' => 'mention', 'url' => 'https://hook.example/', 'attempt' => 1, 'body' => '{}'], time() + 60);
        $csrf = $this->signIn($this->alice);

        $response = $this->request('POST', '/settings/sites/delete', post: ['site_id' => (string) $this->old->id, 'confirm_domain' => ' OLD.example ', 'csrf' => $csrf]);

        self::assertSame(303, $response->status);
        self::assertSame('/settings/sites?notice=' . rawurlencode('Deleted old.example.'), $response->header('location'));
        self::assertNull($this->service(SiteRepository::class)->find($this->old->id));
        foreach (['links', 'pages', 'page_aliases', 'blocklists', 'webhook_deliveries'] as $table) {
            self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM `$table` WHERE site_id = ?", [$this->old->id]), $table);
        }
        self::assertNull($this->service(LinkRepository::class)->find($a));
        self::assertFalse($this->service(SiteDeleter::class)->isDeleting($this->old->id));
        self::assertSame([], $retries->forSite($this->old->id), 'its pending web hook retries go too');
        self::assertCount(1, $retries->forSite($this->live->id));

        // Other sites, including another account's site for the same domain, are untouched.
        self::assertNotNull($this->service(LinkRepository::class)->find($keep));
        self::assertNotNull($this->service(LinkRepository::class)->find($theirLink));
        self::assertNotNull($this->service(SiteRepository::class)->find($theirs->id));

        // New webmentions for it are refused on this account.
        $refused = $this->request('POST', '/alice.example/webmention', post: ['source' => 'https://carol.example/new', 'target' => 'https://old.example/a']);
        self::assertSame(404, $refused->status);
        self::assertSame('target domain not found on this account', self::json($refused)['error_description']);
        self::assertStringNotContainsString('old.example', $this->request('GET', '/settings/sites')->body);
    }

    public function testALargeSiteIsFinishedByTheWorkers(): void
    {
        // One batch of two rows per step, and no time to spend in the request.
        $this->container->set(SiteDeleter::class, fn (): SiteDeleter => new SiteDeleter($this->db, $this->redis, $this->service(Log::class), 0.0, 2));
        for ($i = 1; $i <= 5; $i++) {
            $this->createLink($this->old, "https://old.example/p$i", "https://bob.example/$i");
        }
        $csrf = $this->signIn($this->alice);

        $response = $this->request('POST', '/settings/sites/delete', post: ['site_id' => (string) $this->old->id, 'confirm_domain' => 'old.example', 'csrf' => $csrf]);
        self::assertSame('/settings/sites?notice=' . rawurlencode('Deleting old.example. Its 5 webmentions are being removed in the background.'), $response->header('location'));

        // Archived at once, three webmentions left, and waiting for a worker.
        $site = $this->service(SiteRepository::class)->find($this->old->id);
        self::assertTrue($site?->isArchived());
        self::assertSame(3, (int) $this->db->value('SELECT COUNT(*) FROM links WHERE site_id = ?', [$this->old->id]));
        self::assertSame(1, $this->redis->lLen(SiteDeleter::QUEUE));
        self::assertStringContainsString('Deleting…', $this->request('GET', '/settings/sites')->body);
        self::assertStringContainsString('This site is being deleted.', $this->request('GET', "/settings/sites/{$this->old->id}")->body);
        self::assertSame(404, $this->request('POST', '/alice.example/webmention', post: ['source' => 'https://carol.example/new', 'target' => 'https://old.example/p1'])->status);

        // It can't be unarchived half way.
        $response = $this->request('POST', '/settings/sites/unarchive', post: ['site_id' => (string) $this->old->id, 'csrf' => $csrf]);
        self::assertStringContainsString(rawurlencode('being deleted'), (string) $response->header('location'));
        self::assertTrue($this->service(SiteRepository::class)->find($this->old->id)?->isArchived());

        // The worker takes a batch per turn until it is gone.
        $deleter = $this->service(SiteDeleter::class);
        self::assertFalse($deleter->purgeNext());
        self::assertTrue($deleter->purgeNext());
        self::assertNull($deleter->purgeNext(), 'nothing left to do');
        self::assertNull($this->service(SiteRepository::class)->find($this->old->id));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM links WHERE site_id = ?', [$this->old->id]));
        self::assertFalse($deleter->isDeleting($this->old->id));
        self::assertNotNull($this->service(SiteRepository::class)->find($this->live->id));
    }

    public function testOnlyYourOwnSitesAndTheSignInDomainComesBack(): void
    {
        $mallory = $this->createAccount('mallory.example');
        $theirs  = $this->createSite($mallory, 'mallory.example');
        $csrf    = $this->signIn($this->alice);

        self::assertSame(404, $this->request('GET', "/settings/sites/{$theirs->id}/delete")->status);
        self::assertSame(404, $this->request('POST', '/settings/sites/delete', post: ['site_id' => (string) $theirs->id, 'confirm_domain' => 'mallory.example', 'csrf' => $csrf])->status);
        self::assertSame(404, $this->request('POST', '/settings/sites/unarchive', post: ['site_id' => (string) $theirs->id, 'csrf' => $csrf])->status);
        self::assertNotNull($this->service(SiteRepository::class)->find($theirs->id));

        // Delete both of alice's sites: her sign-in domain is added back, empty, on the next visit.
        foreach ([$this->old, $this->live] as $site) {
            $this->request('POST', '/settings/sites/delete', post: ['site_id' => (string) $site->id, 'confirm_domain' => (string) $site->domain, 'csrf' => $csrf]);
        }
        self::assertSame([], $this->service(SiteRepository::class)->listForAccount($this->alice->id));

        $this->request('GET', '/settings/sites');
        $sites = $this->service(SiteRepository::class)->listForAccount($this->alice->id);
        self::assertCount(1, $sites);
        self::assertSame('alice.example', $sites[0]->domain);
        self::assertTrue($sites[0]->isVerified());
        self::assertNotSame($this->live->id, $sites[0]->id);
    }
}
