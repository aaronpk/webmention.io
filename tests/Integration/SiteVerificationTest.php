<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Model\Account;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\PageRepository;
use Webmention\Storage\SiteRepository;
use Webmention\Tests\Support\IntegrationTestCase;
use Webmention\Webmention\SiteRecheck;
use Webmention\Webmention\SiteVerifier;

/**
 * Sites added before proof of ownership existed: recording, re-checking,
 * and what an unverified site is allowed to do.
 */
final class SiteVerificationTest extends IntegrationTestCase
{
    private Account $alice;
    private Account $mallory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice   = $this->createAccount('alice.example');
        $this->mallory = $this->createAccount('mallory.example');
    }

    public function testNewSitesAreVerifiedAndLegacyOnesAreNot(): void
    {
        $legacy = $this->createSite($this->alice, 'old.example');
        self::assertFalse($legacy->isVerified());

        // The sign-in domain is created verified on an account's first visit.
        $this->signIn($this->mallory);
        $this->request('GET', '/settings/sites');
        $signIn = $this->service(SiteRepository::class)->findByAccountAndDomain($this->mallory->id, 'mallory.example');
        self::assertTrue($signIn?->isVerified());

        // A site added with proof is verified.
        $this->advertise('https://blog.alice.example/', 'https://webmention.io/alice.example/webmention');
        $this->request('POST', '/settings/sites/new', post: ['domain' => 'blog.alice.example', 'csrf' => $this->signIn($this->alice)]);
        self::assertTrue($this->service(SiteRepository::class)->findByAccountAndDomain($this->alice->id, 'blog.alice.example')?->isVerified());
    }

    public function testVerifierAcceptsTheTagOnARecentlyMentionedPage(): void
    {
        $site = $this->createSite($this->alice, 'posts.example');
        $this->createLink($site, 'https://posts.example/2026/hello', 'https://bob.example/reply');
        $this->http->respond('GET', 'https://posts.example/', 200, '<html><body>home, no tag</body></html>', ['Content-Type' => 'text/html']);
        $this->advertise('https://posts.example/2026/hello', 'https://webmention.io/alice.example/webmention');

        $verifier = $this->service(SiteVerifier::class);
        self::assertStringContainsString('does not have a webmention endpoint', (string) $verifier->verify($this->alice, 'posts.example'));
        self::assertNull($verifier->verify($this->alice, 'posts.example', $this->service(SiteRepository::class)->recentPageHrefs($site->id)));

        // A page on another host is never tried.
        self::assertNotNull($verifier->verify($this->alice, 'posts.example', ['https://elsewhere.example/']));
        self::assertNotContains('https://elsewhere.example/', array_column($this->http->requests, 'url'));
    }

    public function testARedirectToAnotherHostProvesNothing(): void
    {
        // A link shortener: its home page has no tag, and its recently mentioned
        // "page" is a short link to the claimant's own site, which does have one.
        $shortener = $this->createSite($this->alice, 'short.example');
        $this->createLink($shortener, 'https://short.example/abc', 'https://bob.example/reply');
        $this->http->respond('GET', 'https://short.example/', 200, '<html><body>Shorten your links</body></html>', ['Content-Type' => 'text/html']);
        $this->http->respond('GET', 'https://short.example/abc', 301, '', ['Location' => 'https://alice.example/post']);
        $this->advertise('https://alice.example/post', 'https://webmention.io/alice.example/webmention');

        $verifier = $this->service(SiteVerifier::class);
        $problem  = $verifier->verify($this->alice, 'short.example', $this->service(SiteRepository::class)->recentPageHrefs($shortener->id));

        self::assertSame('https://short.example/ does not have a webmention endpoint.', $problem);
        self::assertNotContains('https://alice.example/post', array_column($this->http->requests, 'url'), 'the redirect target is never fetched');

        // With no page of its own answering, the redirect is what gets reported.
        $this->http->respond('GET', 'https://short.example/', 301, '', ['Location' => 'https://alice.example/']);
        $this->http->respond('GET', 'http://short.example/', 301, '', ['Location' => 'https://alice.example/']);
        self::assertSame('https://short.example/ redirects to alice.example, which does not prove short.example is yours.', $verifier->verify($this->alice, 'short.example'));
    }

    public function testSameHostRedirectsAndLinkHeadersOnRedirectsStillCount(): void
    {
        $verifier = $this->service(SiteVerifier::class);

        // http to https on the same host, then the tag.
        $this->http->respond('GET', 'https://x.example/', 0, '', [], 'ssl_error');
        $this->http->respond('GET', 'http://x.example/', 301, '', ['Location' => 'http://x.example/home/']);
        $this->advertise('http://x.example/home/', 'https://webmention.io/alice.example/webmention');
        self::assertNull($verifier->verify($this->alice, 'x.example'));

        // The endpoint announced in the Link header of the redirect itself.
        $this->http->respond('GET', 'https://y.example/', 302, '', ['Location' => 'https://elsewhere.example/', 'Link' => '<https://webmention.io/alice.example/webmention>; rel="webmention"']);
        self::assertNull($verifier->verify($this->alice, 'y.example'));

        // A redirect loop on the same host gives up rather than spinning.
        $this->http->respond('GET', 'https://z.example/', 301, '', ['Location' => 'https://z.example/']);
        $this->http->respond('GET', 'http://z.example/', 404, '');
        self::assertStringContainsString('Could not fetch', (string) $verifier->verify($this->alice, 'z.example'));
    }

    public function testRecheckMarksSitesAndLeavesVerifiedOnesAlone(): void
    {
        $sites = $this->service(SiteRepository::class);
        $good  = $this->createSite($this->alice, 'good.example');
        $bad   = $this->createSite($this->alice, 'bad.example');
        $done  = $this->createSite($this->alice, 'done.example');
        $sites->markVerified($done->id);

        $this->advertise('https://good.example/', 'https://webmention.io/alice.example/webmention');
        $this->http->respond('GET', 'https://bad.example/', 200, '<html><head><link rel="webmention" href="https://webmention.io/mallory.example/webmention"></head></html>', ['Content-Type' => 'text/html']);
        $this->http->respond('GET', 'http://bad.example/', 404, 'no');

        $recheck = new SiteRecheck($sites, $this->service(AccountRepository::class), $this->service(SiteVerifier::class), pauseMs: 0);

        $dry = $recheck->run(10);
        self::assertCount(2, $dry, 'verified sites are not re-checked by default');
        self::assertFalse($sites->find($good->id)?->isVerified(), 'a dry run writes nothing');

        $lines = implode("\n", $recheck->run(10, dryRun: false));
        self::assertStringContainsString('good.example (account ' . $this->alice->id . '): verified', $lines);
        self::assertStringContainsString('bad.example (account ' . $this->alice->id . '): not verified: https://bad.example/ points to a different webmention endpoint', $lines);

        self::assertTrue($sites->find($good->id)?->isVerified());
        $badNow = $sites->find($bad->id);
        self::assertFalse($badNow?->isVerified());
        self::assertNotNull($badNow?->verificationCheckedAt);
        self::assertStringContainsString('different webmention endpoint', (string) $badNow?->verificationError);

        // Next run: only the failed one is left, and verified sites only with the flag.
        self::assertCount(1, $recheck->run(10));
        $withRecheck = $recheck->run(10, recheckVerifiedOlderThanDays: 0);
        self::assertCount(3, $withRecheck);
        self::assertStringContainsString('done.example (account ' . $this->alice->id . ', verified)', implode("\n", $withRecheck));
        self::assertTrue($sites->find($done->id)?->isVerified(), 'a failed re-check never downgrades a verified site');
    }

    public function testUnverifiedSitesAreHiddenFromPublicResultsOnlyWhenTheDomainHasAVerifiedHolder(): void
    {
        $sites    = $this->service(SiteRepository::class);
        $aliceSite   = $this->createSite($this->alice, 'shared.example');
        $mallorySite = $this->createSite($this->mallory, 'shared.example');
        $this->createLink($aliceSite, 'https://shared.example/post', 'https://a.example/1');
        $this->createLink($mallorySite, 'https://shared.example/post', 'https://m.example/1');
        $malloryToken = $this->service(AccountRepository::class)->regenerateToken($this->mallory->id);

        $sources = fn (array $query): array => array_column(self::json($this->request('GET', '/api/mentions.jf2', $query))['children'], 'wm-source');

        // Neither verified: both appear, as before.
        self::assertEqualsCanonicalizing(['https://a.example/1', 'https://m.example/1'], $sources(['target' => 'https://shared.example/post']));

        // Alice proves the domain: Mallory's mentions leave the public results but not her own listing.
        $sites->markVerified($aliceSite->id);
        self::assertSame(['https://a.example/1'], $sources(['target' => 'https://shared.example/post']));
        self::assertSame(1, self::json($this->request('GET', '/api/count', ['target' => 'https://shared.example/post']))['count']);
        self::assertSame(['https://m.example/1'], $sources(['token' => $malloryToken]));

        // The per-domain endpoint goes to the verified holder.
        self::assertSame($aliceSite->id, $sites->findByDomain('shared.example')?->id);

        // An unverified site with no verified rival is untouched.
        $lonely = $this->createSite($this->mallory, 'lonely.example');
        $this->createLink($lonely, 'https://lonely.example/post', 'https://m.example/2');
        self::assertSame(['https://m.example/2'], $sources(['target' => 'https://lonely.example/post']));
    }

    public function testCheckNowFromTheSitePage(): void
    {
        $site = $this->createSite($this->alice, 'later.example');
        $csrf = $this->signIn($this->alice);
        $this->http->respond('GET', 'https://later.example/', 200, '<html><body>nothing yet</body></html>', ['Content-Type' => 'text/html']);
        $this->http->respond('GET', 'http://later.example/', 200, '<html><body>nothing yet</body></html>', ['Content-Type' => 'text/html']);

        self::assertStringContainsString('Not verified', $this->request('GET', '/settings/sites')->body);
        $page = $this->request('GET', "/settings/sites/{$site->id}")->body;
        self::assertStringContainsString('Not verified', $page);
        self::assertStringContainsString('action="/settings/sites/verify"', $page);

        $response = $this->request('POST', '/settings/sites/verify', post: ['site_id' => (string) $site->id, 'csrf' => $csrf]);
        self::assertSame(303, $response->status);
        self::assertStringStartsWith("/settings/sites/{$site->id}?checked=", (string) $response->header('location'));
        self::assertStringContainsString(rawurlencode('could not be verified'), (string) $response->header('location'));
        $page = $this->request('GET', "/settings/sites/{$site->id}")->body;
        self::assertStringContainsString('Last checked', $page);
        self::assertStringContainsString('does not have a webmention endpoint', $page);

        $this->advertise('https://later.example/', 'https://webmention.io/alice.example/webmention');
        $response = $this->request('POST', '/settings/sites/verify', post: ['site_id' => (string) $site->id, 'csrf' => $csrf]);
        self::assertStringContainsString(rawurlencode('later.example is verified.'), (string) $response->header('location'));
        self::assertTrue($this->service(SiteRepository::class)->find($site->id)?->isVerified());
        self::assertStringNotContainsString('action="/settings/sites/verify"', $this->request('GET', "/settings/sites/{$site->id}")->body);

        // Not for someone else's site.
        $mcsrf = $this->signIn($this->mallory);
        self::assertSame(404, $this->request('POST', '/settings/sites/verify', post: ['site_id' => (string) $site->id, 'csrf' => $mcsrf])->status);
    }

    private function advertise(string $url, string $endpoint): void
    {
        $this->http->respond('GET', $url, 200, '<html><head><link rel="webmention" href="' . $endpoint . '"></head><body>Hi</body></html>', ['Content-Type' => 'text/html']);
    }
}
