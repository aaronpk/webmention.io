<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Controllers\AuthController;
use Webmention\Controllers\SettingsController;
use Webmention\Model\Account;
use Webmention\Model\Site;
use Webmention\Storage\BlockRepository;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\SiteRepository;
use Webmention\Tests\Support\IntegrationTestCase;

final class DashboardTest extends IntegrationTestCase
{
    private Account $alice;
    private Site $aliceSite;
    private Account $mallory;
    private Site $mallorySite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice       = $this->createAccount('alice.example');
        $this->aliceSite   = $this->createSite($this->alice, 'alice.example', ['callback_url' => 'https://alice.example/hook']);
        $this->mallory     = $this->createAccount('mallory.example');
        $this->mallorySite = $this->createSite($this->mallory, 'mallory.example');
    }

    public function testPagesRequireSignIn(): void
    {
        foreach (['/dashboard', '/settings', '/settings/sites', '/settings/webhooks', '/settings/blocks', '/delete'] as $path) {
            $response = $this->request('GET', $path);
            self::assertSame(302, $response->status, $path);
            self::assertSame('/', $response->header('location'));
        }
    }

    public function testSignedInPagesRender(): void
    {
        $this->createLink($this->aliceSite, 'https://alice.example/post', 'https://bob.example/reply', ['author_name' => 'Bob']);
        $this->signIn($this->alice);

        foreach (['/dashboard', '/settings', '/settings/sites', '/settings/webhooks', '/settings/blocks', '/delete'] as $path) {
            $response = $this->request('GET', $path, $path === '/delete' ? ['source' => 'https://bob.example/reply'] : []);
            self::assertSame(200, $response->status, "$path: " . substr($response->body, 0, 500));
            self::assertStringContainsString('alice.example', $response->body, $path);
        }

        self::assertStringContainsString('https://bob.example/reply', $this->request('GET', '/dashboard')->body);
    }

    public function testPostsRequireCsrf(): void
    {
        $this->signIn($this->alice);

        self::assertSame(403, $this->request('POST', '/settings/change_token')->status);
        self::assertSame(403, $this->request('POST', '/settings/change_token', post: ['csrf' => 'wrong'])->status);
    }

    public function testCannotDeleteAnotherAccountsMention(): void
    {
        $id   = $this->createLink($this->aliceSite, 'https://alice.example/post', 'https://bob.example/reply');
        $csrf = $this->signIn($this->mallory);

        $this->request('POST', '/delete', post: ['id' => (string) $id, 'csrf' => $csrf]);

        self::assertFalse($this->service(LinkRepository::class)->find($id)?->deleted);
    }

    public function testDeleteOneMentionBlocksItsSourceAndNotifies(): void
    {
        $id   = $this->createLink($this->aliceSite, 'https://alice.example/post', 'https://bob.example/reply');
        $csrf = $this->signIn($this->alice);

        $response = $this->request('POST', '/delete', post: ['id' => (string) $id, 'csrf' => $csrf]);

        self::assertSame(303, $response->status);
        self::assertTrue($this->service(LinkRepository::class)->find($id)?->deleted);
        self::assertTrue($this->service(BlockRepository::class)->isSourceBlocked($this->aliceSite->id, 'https://bob.example/reply'));
        self::assertSame(
            ['secret' => null, 'source' => 'https://bob.example/reply', 'target' => 'https://alice.example/post', 'private' => false, 'deleted' => true],
            json_decode((string) $this->http->posts('https://alice.example/hook')[0]['body'], true),
        );
    }

    public function testBlockAndUnblockDomain(): void
    {
        $id   = $this->createLink($this->aliceSite, 'https://alice.example/post', 'https://spam.example/1');
        $csrf = $this->signIn($this->alice);

        $this->request('POST', '/delete', post: ['domain' => 'spam.example', 'csrf' => $csrf]);

        self::assertTrue($this->service(LinkRepository::class)->find($id)?->deleted);
        self::assertSame(['spam.example'], $this->service(BlockRepository::class)->domainsForAccount($this->alice->id));

        $this->request('POST', '/unblock', post: ['domain' => 'spam.example', 'csrf' => $csrf]);

        self::assertSame([], $this->service(BlockRepository::class)->domainsForAccount($this->alice->id));
    }

    public function testBlockedUrlsAreListedFilteredAndPaged(): void
    {
        $id   = $this->createLink($this->aliceSite, 'https://alice.example/post', 'https://bob.example/reply');
        $csrf = $this->signIn($this->alice);
        $this->request('POST', '/delete', post: ['id' => (string) $id, 'csrf' => $csrf]);

        $blocks = $this->service(BlockRepository::class);
        $blocks->blockSource($this->mallorySite->id, 'https://bob.example/reply'); // someone else's, never shown to alice

        $page = $this->request('GET', '/settings/blocks')->body;
        self::assertStringContainsString('1 blocked URL.', $page);
        self::assertStringContainsString('<a href="https://bob.example/reply" rel="nofollow noopener">https://bob.example/reply</a>', $page);
        self::assertStringContainsString('<td class="nowrap">alice.example</td>', $page);
        self::assertSame(1, substr_count($page, 'action="/unblock-source"'));

        // Filtering is a plain substring; LIKE wildcards are literal.
        self::assertStringContainsString('1 of 1 blocked URL match.', $this->request('GET', '/settings/blocks', ['q' => 'bob.exam'])->body);
        self::assertStringContainsString('0 of 1 blocked URL match.', $this->request('GET', '/settings/blocks', ['q' => 'carol'])->body);
        self::assertStringContainsString('0 of 1 blocked URL match.', $this->request('GET', '/settings/blocks', ['q' => 'b%b'])->body);
        self::assertStringContainsString('0 of 1 blocked URL match.', $this->request('GET', '/settings/blocks', ['q' => 'b_b'])->body);

        // Blocking the same URL again is a no-op, so the table never grows duplicates.
        $blocks->blockSource($this->aliceSite->id, 'https://bob.example/reply');
        self::assertSame(1, $blocks->countSourcesForAccount($this->alice->id));

        // Pages of 50, newest first.
        for ($i = 1; $i <= 54; $i++) {
            $blocks->blockSource($this->aliceSite->id, "https://spam.example/$i");
        }
        $first = $this->request('GET', '/settings/blocks')->body;
        self::assertStringContainsString('55 blocked URLs.', $first);
        self::assertSame(50, substr_count($first, 'action="/unblock-source"'));
        self::assertStringContainsString('Page 1 of 2', $first);
        self::assertStringContainsString('https://spam.example/54', $first);
        self::assertStringNotContainsString('https://bob.example/reply', $first);

        $second = $this->request('GET', '/settings/blocks', ['page' => '1'])->body;
        self::assertSame(5, substr_count($second, 'action="/unblock-source"'));
        self::assertStringContainsString('https://bob.example/reply', $second);
        self::assertStringContainsString('Page 2 of 2', $second);

        // A page past the end shows the last page rather than nothing.
        self::assertStringContainsString('Page 2 of 2', $this->request('GET', '/settings/blocks', ['page' => '99'])->body);
    }

    public function testUnblockSourceIsScopedToTheAccount(): void
    {
        $blocks = $this->service(BlockRepository::class);
        $blocks->blockSource($this->aliceSite->id, 'https://bob.example/reply');
        $blocks->blockSource($this->aliceSite->id, 'https://bob.example/other');

        // Mallory can't unblock it, even knowing the site id.
        $csrf = $this->signIn($this->mallory);
        self::assertSame(404, $this->request('POST', '/unblock-source', post: ['site_id' => (string) $this->aliceSite->id, 'source' => 'https://bob.example/reply', 'csrf' => $csrf])->status);
        self::assertTrue($blocks->isSourceBlocked($this->aliceSite->id, 'https://bob.example/reply'));

        $csrf     = $this->signIn($this->alice);
        $response = $this->request('POST', '/unblock-source', post: [
            'site_id' => (string) $this->aliceSite->id,
            'source'  => 'https://bob.example/reply',
            'q'       => 'bob',
            'page'    => '0',
            'csrf'    => $csrf,
        ]);

        self::assertSame(303, $response->status);
        self::assertSame('/settings/blocks?q=bob', $response->header('location'));
        self::assertFalse($blocks->isSourceBlocked($this->aliceSite->id, 'https://bob.example/reply'));
        self::assertTrue($blocks->isSourceBlocked($this->aliceSite->id, 'https://bob.example/other'));
    }

    public function testCannotConfigureAnotherAccountsWebhook(): void
    {
        $csrf = $this->signIn($this->mallory);

        $response = $this->request('POST', '/webhook/configure', post: [
            'site_id'      => (string) $this->aliceSite->id,
            'callback_url' => 'https://mallory.example/steal',
            'csrf'         => $csrf,
        ]);

        self::assertSame(404, $response->status);
        self::assertSame('https://alice.example/hook', $this->service(SiteRepository::class)->find($this->aliceSite->id)?->callbackUrl);
    }

    public function testConfigureOwnWebhook(): void
    {
        $csrf = $this->signIn($this->alice);

        $this->request('POST', '/webhook/configure', post: [
            'site_id'         => (string) $this->aliceSite->id,
            'callback_url'    => 'https://alice.example/new-hook',
            'callback_secret' => str_repeat('x', 80),
            'csrf'            => $csrf,
        ]);

        $site = $this->service(SiteRepository::class)->find($this->aliceSite->id);
        self::assertSame('https://alice.example/new-hook', $site?->callbackUrl);
        self::assertSame(50, strlen((string) $site?->callbackSecret));
        self::assertFalse($site?->archiveAvatars);
    }

    public function testAddSiteNormalizesTheDomain(): void
    {
        $csrf = $this->signIn($this->alice);
        $this->advertise('blog.alice.example', 'https://webmention.io/alice.example/webmention');

        $this->request('POST', '/settings/sites/new', post: ['domain' => 'HTTPS://Blog.Alice.Example/about', 'csrf' => $csrf]);
        $this->request('POST', '/settings/sites/new', post: ['domain' => 'blog.alice.example', 'csrf' => $csrf]);
        $this->request('POST', '/settings/sites/new', post: ['domain' => 'not a domain', 'csrf' => $csrf]);

        $domains = array_map(static fn (Site $s): ?string => $s->domain, $this->service(SiteRepository::class)->listForAccount($this->alice->id));
        self::assertSame(['alice.example', 'blog.alice.example'], $domains);
    }

    public function testASiteMustAdvertiseTheAccountsEndpointBeforeItCanBeAdded(): void
    {
        $csrf = $this->signIn($this->mallory);

        // Nothing there, someone else's endpoint, an unreachable host: all refused.
        $this->http->respond('GET', 'https://victim.example/', 200, '<html><body>Hi</body></html>', ['Content-Type' => 'text/html']);
        $this->http->respond('GET', 'http://victim.example/', 200, '<html><body>Hi</body></html>', ['Content-Type' => 'text/html']);
        $this->advertise('alice.example', 'https://webmention.io/alice.example/webmention');
        $this->http->respond('GET', 'https://down.example/', 0, '', [], 'timeout');
        $this->http->respond('GET', 'http://down.example/', 0, '', [], 'timeout');

        foreach (['victim.example' => 'does not have a webmention endpoint', 'alice.example' => 'different webmention endpoint', 'down.example' => 'Could not fetch'] as $domain => $why) {
            $response = $this->request('POST', '/settings/sites/new', post: ['domain' => $domain, 'csrf' => $csrf]);
            self::assertSame(303, $response->status);
            self::assertStringContainsString(rawurlencode($why), (string) $response->header('location'), $domain);
            self::assertStringContainsString(rawurlencode('href="https://webmention.io/mallory.example/webmention"'), (string) $response->header('location'));
        }

        $domains = array_map(static fn (Site $s): ?string => $s->domain, $this->service(SiteRepository::class)->listForAccount($this->mallory->id));
        self::assertSame(['mallory.example'], $domains);
    }

    public function testASiteIsAcceptedWithALinkHeaderOrTagOrPerDomainEndpoint(): void
    {
        $csrf = $this->signIn($this->mallory);

        // A Link header, on the http site after https fails.
        $this->http->respond('GET', 'https://header.example/', 0, '', [], 'ssl_error');
        $this->http->respond('GET', 'http://header.example/', 200, '<html></html>', [
            'Content-Type' => 'text/html',
            'Link'         => '</mallory.example/webmention>; rel="webmention"',
        ]);
        // Trailing slash and upper case in the tag don't matter; nor does a relative URL.
        $this->http->respond('GET', 'https://tag.example/', 200, '<html><head><link rel="webmention" href="HTTPS://Webmention.IO/mallory.example/webmention/"></head></html>', ['Content-Type' => 'text/html']);
        $this->http->respond('GET', 'https://perdomain.example/', 200, '<html><body><a rel="webmention" href="https://webmention.io/d/perdomain.example/webmention">wm</a></body></html>', ['Content-Type' => 'text/html']);

        // The Link header value above is relative to the page, so it resolves to header.example, not here.
        $this->request('POST', '/settings/sites/new', post: ['domain' => 'header.example', 'csrf' => $csrf]);
        $this->request('POST', '/settings/sites/new', post: ['domain' => 'tag.example', 'csrf' => $csrf]);
        $this->request('POST', '/settings/sites/new', post: ['domain' => 'perdomain.example', 'csrf' => $csrf]);

        $domains = array_map(static fn (Site $s): ?string => $s->domain, $this->service(SiteRepository::class)->listForAccount($this->mallory->id));
        self::assertSame(['mallory.example', 'tag.example', 'perdomain.example'], $domains);
    }

    public function testACallbackUrlThatCanNeverBeReachedIsRefused(): void
    {
        $csrf = $this->signIn($this->alice);

        $response = $this->request('POST', '/webhook/configure', post: [
            'site_id'      => (string) $this->aliceSite->id,
            'callback_url' => 'ftp://alice.example/hook',
            'csrf'         => $csrf,
        ]);

        self::assertSame(400, $response->status);
        self::assertSame('https://alice.example/hook', $this->service(SiteRepository::class)->find($this->aliceSite->id)?->callbackUrl);
    }

    private function advertise(string $domain, string $endpoint): void
    {
        $this->http->respond(
            'GET',
            "https://$domain/",
            200,
            '<html><head><link rel="webmention" href="' . $endpoint . '"></head><body>Hi</body></html>',
            ['Content-Type' => 'text/html'],
        );
    }

    public function testASiteIsNeverCreatedTwiceForTheSameAccount(): void
    {
        $sites = $this->service(SiteRepository::class);

        self::assertSame($this->aliceSite->id, $sites->findOrCreate($this->alice->id, 'alice.example')->id);

        $new = $sites->findOrCreate($this->alice->id, 'new.alice.example');
        self::assertSame($new->id, $sites->findOrCreate($this->alice->id, 'new.alice.example')->id);
        self::assertCount(1, $this->db->all('SELECT id FROM sites WHERE account_id = ? AND domain = ?', [$this->alice->id, 'new.alice.example']));

        if ($this->db->all('SHOW INDEX FROM sites WHERE Key_name = "account_domain"') === []) {
            self::markTestSkipped('Apply database/migrations/2026-09-14-sites-unique-domain.sql to the test database.');
        }

        // The database itself refuses a second row, which is what findOrCreate() relies on under concurrency.
        $this->expectException(\PDOException::class);
        $this->expectExceptionCode('23000');
        $sites->create($this->alice->id, 'alice.example');
    }

    public function testAccountNamesForProfileUrls(): void
    {
        self::assertSame('aaronparecki.com', AuthController::domainFor('https://aaronparecki.com/'));
        self::assertSame('example.com_~me_', AuthController::domainFor('https://Example.com/~me/'));
        self::assertSame('example.com', SettingsController::normalizeDomain(' https://Example.com/path?q '));
        self::assertNull(SettingsController::normalizeDomain('localhost'));
    }
}
