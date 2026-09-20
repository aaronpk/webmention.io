<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Model\Account;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\PageRepository;
use Webmention\Storage\SiteRepository;
use Webmention\Tests\Support\IntegrationTestCase;
use Webmention\Webmention\SiteOwnership;
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

    public function testSigningInWithADomainAnotherAccountHasVerifiedDoesNotVerifyItHere(): void
    {
        // alice.example verified tv.example, whose pages advertise alice's endpoint.
        $this->createSite($this->alice, 'tv.example', ['verified_at' => '2026-01-01 00:00:00']);
        $this->createSite($this->alice, 'alice.example', ['verified_at' => '2026-01-01 00:00:00']);
        $this->createLink($this->service(SiteRepository::class)->findByAccountAndDomain($this->alice->id, 'tv.example'), 'https://tv.example/post', 'https://bob.example/reply');
        $this->advertise('https://tv.example/', 'https://webmention.io/alice.example/webmention');

        // Then the owner signs in as tv.example, which makes a second account.
        $tv = $this->createAccount('tv.example');
        $this->signIn($tv);
        $page = $this->request('GET', '/settings/sites')->body;

        $site = $this->service(SiteRepository::class)->findByAccountAndDomain($tv->id, 'tv.example');
        self::assertNotNull($site, 'the row exists');
        self::assertFalse($site->isVerified(), 'but is not verified by the sign-in alone');
        self::assertStringContainsString('points to a different webmention endpoint', (string) $site->verificationError);
        self::assertStringContainsString('<strong>tv.example is already set up on the account alice.example</strong>', $page);
        self::assertStringContainsString('sign in as alice.example', $page);
        self::assertStringContainsString('<span class="badge badge-error">Not verified</span>', $page);
        self::assertStringContainsString('Check now', $this->request('GET', "/settings/sites/{$site->id}")->body);

        // The public API keeps showing alice's mentions for the domain, and only hers.
        $children = self::json($this->request('GET', '/api/mentions.jf2', ['target' => 'https://tv.example/post']))['children'];
        self::assertSame(['https://bob.example/reply'], array_column($children, 'wm-source'));

        // No conflict card once the domain's pages point here and it is checked again.
        $this->advertise('https://tv.example/', 'https://webmention.io/tv.example/webmention');
        $this->request('POST', '/settings/sites/verify', post: ['site_id' => (string) $site->id, 'csrf' => $this->signIn($tv)]);
        self::assertTrue($this->service(SiteRepository::class)->find($site->id)?->isVerified());
        self::assertStringNotContainsString('is already set up on the account', $this->request('GET', '/settings/sites')->body);

        // With no other account holding the domain, the sign-in shortcut still needs no fetch.
        $this->http->requests = [];
        $solo = $this->createAccount('solo.example');
        $this->signIn($solo);
        $this->request('GET', '/settings/sites');
        self::assertTrue($this->service(SiteRepository::class)->findByAccountAndDomain($solo->id, 'solo.example')?->isVerified());
        self::assertSame([], $this->http->requests);
    }

    public function testADomainThatMovesToAnotherAccountLosesItsOldVerification(): void
    {
        $sites = $this->service(SiteRepository::class);
        $old   = $this->createSite($this->alice, 'tv.example', ['verified_at' => '2026-01-01 00:00:00']);
        $this->createSite($this->alice, 'alice.example', ['verified_at' => '2026-01-01 00:00:00']);
        $this->advertise('https://tv.example/', 'https://webmention.io/alice.example/webmention');

        $tv = $this->createAccount('tv.example');
        $this->signIn($tv);
        $this->request('GET', '/settings/sites');
        $new = $sites->findByAccountAndDomain($tv->id, 'tv.example');
        self::assertFalse($new?->isVerified());

        // The owner repoints the site at the new account and checks it.
        $this->advertise('https://tv.example/', 'https://webmention.io/tv.example/webmention');
        $response = $this->request('POST', '/settings/sites/verify', post: ['site_id' => (string) $new?->id, 'csrf' => $this->signIn($tv)]);
        self::assertSame("/settings/sites/{$new?->id}", $response->header('location'));
        self::assertStringContainsString('tv.example is verified.', $this->request('GET', "/settings/sites/{$new?->id}")->body);
        self::assertTrue($sites->find((int) $new?->id)?->isVerified());

        $oldNow = $sites->find($old->id);
        self::assertFalse($oldNow?->isVerified(), 'the old account no longer holds the domain');
        self::assertSame('tv.example now advertises the endpoint of the account tv.example, so its webmentions go there.', $oldNow?->verificationError);

        // The old account sees why on the site's page, and Check now there says so too.
        $csrf = $this->signIn($this->alice);
        self::assertStringContainsString('now advertises the endpoint of the account tv.example', $this->request('GET', "/settings/sites/{$old->id}")->body);
        $response = $this->request('POST', '/settings/sites/verify', post: ['site_id' => (string) $old->id, 'csrf' => $csrf]);
        self::assertSame("/settings/sites/{$old->id}", $response->header('location'));
        self::assertStringContainsString('could not be verified.', $this->request('GET', "/settings/sites/{$old->id}")->body);
        self::assertFalse($sites->find($old->id)?->isVerified());

        // Public results now come from the new account's row only.
        $this->createLink($sites->find($old->id), 'https://tv.example/post', 'https://a.example/old');
        $this->createLink($sites->find((int) $new?->id), 'https://tv.example/post', 'https://a.example/new');
        $children = self::json($this->request('GET', '/api/mentions.jf2', ['target' => 'https://tv.example/post']))['children'];
        self::assertSame(['https://a.example/new'], array_column($children, 'wm-source'));
    }

    public function testTheNightlyRecheckDowngradesOnlyForAMoveToAnotherAccount(): void
    {
        $sites   = $this->service(SiteRepository::class);
        $moved   = $this->createSite($this->alice, 'moved.example', ['verified_at' => '2026-01-01 00:00:00']);
        $shared  = $this->createSite($this->alice, 'shared.example', ['verified_at' => '2026-01-01 00:00:00']);
        $shared2 = $this->createSite($this->mallory, 'shared.example', ['verified_at' => '2026-01-01 00:00:00']);
        $typo    = $this->createSite($this->alice, 'typo.example', ['verified_at' => '2026-01-01 00:00:00']);
        $down    = $this->createSite($this->alice, 'down.example', ['verified_at' => '2026-01-01 00:00:00']);

        $this->advertise('https://moved.example/', 'https://webmention.io/mallory.example/webmention');
        $this->advertise('https://shared.example/', 'https://webmention.io/d/shared.example/webmention');
        $this->advertise('https://typo.example/', 'https://webmention.io/nobody.example/webmention');
        $this->http->respond('GET', 'https://down.example/', 0, '', [], 'timeout');
        $this->http->respond('GET', 'http://down.example/', 0, '', [], 'timeout');

        $recheck = new SiteRecheck($sites, $this->service(AccountRepository::class), $this->service(SiteOwnership::class), pauseMs: 0);

        // A dry run reports but changes nothing.
        $dry = implode("
", $recheck->run(10, recheckVerifiedOlderThanDays: 0));
        self::assertStringContainsString('moved.example (account ' . $this->alice->id . ', verified): not verified', $dry);
        self::assertTrue($sites->find($moved->id)?->isVerified());

        $lines = implode("
", $recheck->run(10, dryRun: false, recheckVerifiedOlderThanDays: 0));
        self::assertStringContainsString("moved.example (account {$this->alice->id}, verified): unverified: moved.example now advertises the endpoint of the account mallory.example", $lines);

        self::assertFalse($sites->find($moved->id)?->isVerified(), 'a domain that names another account has moved');
        self::assertTrue($sites->find($shared->id)?->isVerified(), 'the /d/ form names nobody, so both keep it');
        self::assertTrue($sites->find($shared2->id)?->isVerified());
        self::assertTrue($sites->find($typo->id)?->isVerified(), 'an endpoint naming no existing account is just a wrong tag');
        self::assertStringContainsString('different webmention endpoint', (string) $sites->find($typo->id)?->verificationError);
        self::assertTrue($sites->find($down->id)?->isVerified(), 'being down is not a move');
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

    public function testApexAndWwwAreTheSameOwner(): void
    {
        $verifier = $this->service(SiteVerifier::class);

        // example.com redirects to www.example.com, which carries the tag.
        $this->http->respond('GET', 'https://apex.example/', 301, '', ['Location' => 'https://www.apex.example/']);
        $this->advertise('https://www.apex.example/', 'https://webmention.io/alice.example/webmention');
        self::assertNull($verifier->verify($this->alice, 'apex.example'));

        // And the other way round.
        $this->http->respond('GET', 'https://www.other.example/', 301, '', ['Location' => 'https://other.example/']);
        $this->advertise('https://other.example/', 'https://webmention.io/alice.example/webmention');
        self::assertNull($verifier->verify($this->alice, 'www.other.example'));

        // But not to a different name, even one that starts with www.
        $this->http->respond('GET', 'https://third.example/', 301, '', ['Location' => 'https://www.alice.example/']);
        $this->http->respond('GET', 'http://third.example/', 301, '', ['Location' => 'https://www.alice.example/']);
        $this->advertise('https://www.alice.example/', 'https://webmention.io/alice.example/webmention');
        self::assertSame('https://third.example/ redirects to www.alice.example, which does not prove third.example is yours.', $verifier->verify($this->alice, 'third.example'));
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

        $recheck = new SiteRecheck($sites, $this->service(AccountRepository::class), $this->service(SiteOwnership::class), pauseMs: 0);

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
        self::assertSame("/settings/sites/{$site->id}", $response->header('location'));
        $page = $this->request('GET', "/settings/sites/{$site->id}")->body;
        self::assertStringContainsString('could not be verified', $page);
        self::assertStringContainsString('Last checked', $page);
        self::assertStringContainsString('does not have a webmention endpoint', $page);

        $this->advertise('https://later.example/', 'https://webmention.io/alice.example/webmention');
        $response = $this->request('POST', '/settings/sites/verify', post: ['site_id' => (string) $site->id, 'csrf' => $csrf]);
        self::assertSame("/settings/sites/{$site->id}", $response->header('location'));
        self::assertTrue($this->service(SiteRepository::class)->find($site->id)?->isVerified());
        $page = $this->request('GET', "/settings/sites/{$site->id}")->body;
        self::assertStringContainsString('later.example is verified.', $page);
        self::assertStringNotContainsString('action="/settings/sites/verify"', $page);

        // Not for someone else's site.
        $mcsrf = $this->signIn($this->mallory);
        self::assertSame(404, $this->request('POST', '/settings/sites/verify', post: ['site_id' => (string) $site->id, 'csrf' => $mcsrf])->status);
    }

    private function advertise(string $url, string $endpoint): void
    {
        $this->http->respond('GET', $url, 200, '<html><head><link rel="webmention" href="' . $endpoint . '"></head><body>Hi</body></html>', ['Content-Type' => 'text/html']);
    }
}
