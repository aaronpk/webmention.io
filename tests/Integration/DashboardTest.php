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

        $this->request('POST', '/settings/sites/new', post: ['domain' => 'HTTPS://Blog.Alice.Example/about', 'csrf' => $csrf]);
        $this->request('POST', '/settings/sites/new', post: ['domain' => 'blog.alice.example', 'csrf' => $csrf]);
        $this->request('POST', '/settings/sites/new', post: ['domain' => 'not a domain', 'csrf' => $csrf]);

        $domains = array_map(static fn (Site $s): ?string => $s->domain, $this->service(SiteRepository::class)->listForAccount($this->alice->id));
        self::assertSame(['alice.example', 'blog.alice.example'], $domains);
    }

    public function testAccountNamesForProfileUrls(): void
    {
        self::assertSame('aaronparecki.com', AuthController::domainFor('https://aaronparecki.com/'));
        self::assertSame('example.com_~me_', AuthController::domainFor('https://Example.com/~me/'));
        self::assertSame('example.com', SettingsController::normalizeDomain(' https://Example.com/path?q '));
        self::assertNull(SettingsController::normalizeDomain('localhost'));
    }
}
