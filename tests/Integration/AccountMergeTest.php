<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Model\Account;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\MuteRepository;
use Webmention\Storage\PageRepository;
use Webmention\Storage\SiteRepository;
use Webmention\Storage\WebhookDeliveryRepository;
use Webmention\Tests\Support\IntegrationTestCase;
use Webmention\Webmention\AccountMerger;

/**
 * Bringing an old account into the current one after a move (issue 223).
 */
final class AccountMergeTest extends IntegrationTestCase
{
    private Account $new;
    private Account $old;

    protected function setUp(): void
    {
        parent::setUp();

        $this->new = $this->createAccount('www.steele.example');
        $this->createSite($this->new, 'www.steele.example', ['verified_at' => '2026-09-01 00:00:00']);
        $this->old = $this->createAccount('steele.example');
        $this->createSite($this->old, 'steele.example');
    }

    public function testTheOldDomainMustPointAtTheCurrentAccount(): void
    {
        $merger = $this->service(AccountMerger::class);

        // Redirects to one of the new account's verified sites: proved.
        $this->http->respond('GET', 'https://steele.example/', 301, '', ['Location' => 'https://www.steele.example/']);
        self::assertSame($this->old->id, $merger->check($this->new, 'steele.example')?->id);
        self::assertSame($this->old->id, $merger->check($this->new, 'https://STEELE.example/about')?->id, 'a URL or odd case is fine');

        // Redirects somewhere else: not proved, and the message says where it went.
        $this->http->respond('GET', 'https://steele.example/', 301, '', ['Location' => 'https://elsewhere.example/']);
        $this->http->respond('GET', 'http://steele.example/', 301, '', ['Location' => 'https://elsewhere.example/']);
        $this->http->respond('GET', 'https://elsewhere.example/', 200, '<html><body>hi</body></html>', ['Content-Type' => 'text/html']);
        $problem = $merger->check($this->new, 'steele.example');
        self::assertIsString($problem);
        self::assertStringContainsString('redirects to elsewhere.example', $problem);

        // Advertises the new account's endpoint instead: proved.
        $this->http->respond('GET', 'https://steele.example/', 200, '<html><head><link rel="webmention" href="https://webmention.io/www.steele.example/webmention"></head></html>', ['Content-Type' => 'text/html']);
        self::assertSame($this->old->id, $merger->check($this->new, 'steele.example')?->id);

        // The other cases.
        self::assertSame('No other account has nobody.example.', (string) $merger->check($this->new, 'nobody.example'));
        self::assertSame('There is no account named nobody.', (string) $merger->check($this->new, 'nobody'));
        self::assertStringContainsString('signed in with', (string) $merger->check($this->new, 'www.steele.example'));
        self::assertStringContainsString("Enter the site's domain", (string) $merger->check($this->new, '   '));
    }

    public function testAnAccountIsFoundByItsNameOrByASiteItHolds(): void
    {
        $merger = $this->service(AccountMerger::class);

        // An early account named by a username, with a domain that differs.
        $early = $this->createAccount('oauth.example', 'oauth');
        $this->createSite($early, 'oauth.example', ['verified_at' => '2021-01-01 00:00:00']);
        $this->http->respond('GET', 'https://oauth.example/', 200, '<html><head><link rel="webmention" href="https://webmention.io/www.steele.example/webmention"></head></html>', ['Content-Type' => 'text/html']);
        self::assertSame($early->id, $merger->check($this->new, 'oauth')?->id, 'by username, proved on its domain');
        self::assertSame($early->id, $merger->check($this->new, 'oauth.example')?->id, 'by domain');

        // A site that is not the other account's own domain finds the account holding it verified.
        $holder = $this->createAccount('holder.example');
        $this->createSite($holder, 'holder.example', ['verified_at' => '2026-01-01 00:00:00']);
        $this->createSite($holder, 'blog.example', ['verified_at' => '2026-01-01 00:00:00']);
        $this->http->respond('GET', 'https://blog.example/', 301, '', ['Location' => 'https://www.steele.example/']);
        self::assertSame($holder->id, $merger->check($this->new, 'blog.example')?->id);
        self::assertSame(['holder.example', 'blog.example'], $merger->preview($holder)['sites'], 'the whole account comes, and the preview says so');

        // An unverified row on another account does not identify it; the new account's own row is never it.
        $this->createSite($this->createAccount('squatter.example'), 'plain.example');
        self::assertSame('No other account has plain.example.', (string) $merger->check($this->new, 'plain.example'));
        $this->createSite($this->new, 'mine.example', ['verified_at' => '2026-01-01 00:00:00']);
        self::assertSame('No other account has mine.example.', (string) $merger->check($this->new, 'mine.example'));
    }

    public function testADomainBothAccountsHaveFoldsIntoOneVerifiedRow(): void
    {
        // oauth.net: the new account has an old unverified row, the other account a verified one.
        $mine   = $this->createSite($this->new, 'oauth.example');
        $early  = $this->createAccount('oauth.example', 'oauth');
        $theirs = $this->createSite($early, 'oauth.example', ['verified_at' => '2021-01-01 00:00:00']);
        $a = $this->createLink($mine, 'https://oauth.example/a', 'https://x.example/1');
        $b = $this->createLink($theirs, 'https://oauth.example/a', 'https://y.example/2');
        $c = $this->createLink($theirs, 'https://oauth.example/b', 'https://z.example/3');
        $csrf = $this->signIn($this->new);
        $this->http->respond('GET', 'https://oauth.example/', 200, '<html><head><link rel="webmention" href="https://webmention.io/www.steele.example/webmention"></head></html>', ['Content-Type' => 'text/html']);

        $response = $this->request('POST', '/settings/merge-account', post: ['site' => 'oauth.example', 'csrf' => $csrf]);
        self::assertSame(200, $response->status, $response->body);
        self::assertStringContainsString('Merge the account oauth into this one?', $response->body);
        self::assertStringContainsString('<dt>Account</dt><dd>oauth (oauth.example)</dd>', $response->body);
        self::assertStringContainsString('<dd>2</dd>', $response->body);

        $response = $this->request('POST', '/settings/merge-account/confirm', post: ['old_domain' => 'oauth.example', 'csrf' => $csrf]);
        self::assertSame('/settings/sites?account_merged=' . rawurlencode('Merged the account oauth: 1 site and 2 webmentions are now on this account.') . '#bring', $response->header('location'));

        $sites = $this->service(SiteRepository::class);
        $rows  = $this->db->all('SELECT id, verified_at FROM sites WHERE domain = ?', ['oauth.example']);
        self::assertCount(1, $rows, 'one row left');
        self::assertSame($mine->id, (int) $rows[0]['id'], 'the account keeps its own row');
        $links = $this->service(LinkRepository::class);
        foreach ([$a, $b, $c] as $id) {
            self::assertSame($mine->id, $links->find($id)?->siteId, "link $id is on the surviving row");
            self::assertSame($this->new->id, $links->find($id)?->accountId);
        }
        self::assertNull($this->service(AccountRepository::class)->find($early->id));
    }

    public function testMergeMovesEverythingAndDeletesTheOldAccount(): void
    {
        $oldSite   = $this->service(SiteRepository::class)->findByAccountAndDomain($this->old->id, 'steele.example');
        $otherSite = $this->createSite($this->old, 'blog.steele.example');
        $shared    = $this->createSite($this->old, 'www.steele.example'); // the new account has this domain too
        $newShared = $this->service(SiteRepository::class)->findByAccountAndDomain($this->new->id, 'www.steele.example');

        $a = $this->createLink($oldSite, 'https://steele.example/post', 'https://x.example/1');
        $b = $this->createLink($otherSite, 'https://blog.steele.example/p', 'https://x.example/2');
        $c = $this->createLink($shared, 'https://www.steele.example/same', 'https://x.example/3');
        $d = $this->createLink($shared, 'https://www.steele.example/same', 'https://x.example/dup'); // same source as one the new site already has
        $this->createLink($newShared, 'https://www.steele.example/same', 'https://x.example/dup');
        $e = $this->createLink($shared, 'https://www.steele.example/only-old', 'https://x.example/4');
        $mine = $this->createLink($newShared, 'https://www.steele.example/mine', 'https://x.example/5');

        $this->db->insert('blocks', ['account_id' => $this->old->id, 'domain' => 'spam.example', 'created_at' => '2026-01-01 00:00:00']);
        $this->db->insert('blocks', ['account_id' => $this->old->id, 'domain' => 'both.example', 'created_at' => '2026-01-01 00:00:00']);
        $this->db->insert('blocks', ['account_id' => $this->new->id, 'domain' => 'both.example', 'created_at' => '2026-01-01 00:00:00']);
        $this->service(MuteRepository::class)->add($this->old->id, 'source', 'noisy.example');
        $this->db->insert('webhook_deliveries', ['site_id' => $shared->id, 'kind' => 'mention', 'url' => 'https://hook.example/', 'request_body' => '{}', 'created_at' => '2026-01-01 00:00:00']);
        $oldToken = $this->service(AccountRepository::class)->regenerateToken($this->old->id);

        $moved = $this->service(AccountMerger::class)->merge($this->new, $this->old);

        self::assertSame(3, $moved['sites']);
        self::assertSame(4, $moved['mentions'], 'a, b, c and e moved; the duplicate was dropped');

        $sites = $this->service(SiteRepository::class);
        $domains = array_map(static fn ($s) => $s->domain, $sites->listForAccount($this->new->id));
        sort($domains);
        self::assertSame(['blog.steele.example', 'steele.example', 'www.steele.example'], $domains, 'the shared domain was folded, not duplicated');
        self::assertNull($sites->find($shared->id));

        $links = $this->service(LinkRepository::class);
        foreach ([$a, $b, $c, $e, $mine] as $id) {
            $link = $links->find($id);
            self::assertNotNull($link, "link $id survives");
            self::assertSame($this->new->id, $link->accountId);
        }
        self::assertNull($links->find($d), 'the duplicate source on the same page was dropped');
        self::assertSame($newShared->id, $links->find($c)?->siteId);
        self::assertSame($newShared->id, $links->find($e)?->siteId);
        self::assertSame(1, $this->db->value('SELECT COUNT(*) FROM pages WHERE site_id = ? AND href = ?', [$newShared->id, 'https://www.steele.example/same']), 'one page for the shared URL');
        self::assertNotNull($this->service(PageRepository::class)->findBySiteAndHref($newShared->id, 'https://www.steele.example/only-old'));

        self::assertSame(['both.example', 'spam.example'], array_column($this->db->all('SELECT domain FROM blocks WHERE account_id = ? ORDER BY domain', [$this->new->id]), 'domain'));
        self::assertCount(1, $this->service(MuteRepository::class)->forAccount($this->new->id));
        self::assertCount(1, $this->service(WebhookDeliveryRepository::class)->recentForSite($newShared->id));

        self::assertNull($this->service(AccountRepository::class)->find($this->old->id), 'the old account is gone');
        self::assertNull($this->service(AccountRepository::class)->findByToken($oldToken), 'and its token no longer works');
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM links WHERE account_id = ?', [$this->old->id]));
        self::assertSame(401, $this->request('GET', '/api/mentions.jf2', ['token' => $oldToken])->status);
    }

    public function testTheSettingsFlowChecksThenConfirms(): void
    {
        $csrf = $this->signIn($this->new);
        $this->createLink($this->service(SiteRepository::class)->findByAccountAndDomain($this->old->id, 'steele.example'), 'https://steele.example/post', 'https://x.example/1');

        self::assertStringContainsString('<h2>Bring in a site from another account</h2>', $this->request('GET', '/settings/sites')->body);
        self::assertStringNotContainsString('Moved to a new domain', $this->request('GET', '/settings')->body, 'no longer on Settings');

        // Not proved: back to the card on the Sites page with the reason.
        $this->http->respond('GET', 'https://steele.example/', 200, '<html><body>nothing</body></html>', ['Content-Type' => 'text/html']);
        $this->http->respond('GET', 'http://steele.example/', 200, '<html><body>nothing</body></html>', ['Content-Type' => 'text/html']);
        $response = $this->request('POST', '/settings/merge-account', post: ['site' => 'steele.example', 'csrf' => $csrf]);
        self::assertSame(303, $response->status);
        self::assertStringStartsWith('/settings/sites?account_merge_error=', (string) $response->header('location'));
        self::assertStringEndsWith('#bring', (string) $response->header('location'));

        $page = $this->request('GET', '/settings/sites', ['account_merge_error' => 'That did not work'])->body;
        $card = substr($page, (int) strpos($page, 'id="bring"'));
        $card = substr($card, 0, (int) strpos($card, '</section>'));
        self::assertStringContainsString('<p class="alert">That did not work</p>', $card, 'the message is in the card the anchor lands on');

        // Confirming without a check first is refused.
        $response = $this->request('POST', '/settings/merge-account/confirm', post: ['old_domain' => 'steele.example', 'csrf' => $csrf]);
        self::assertStringContainsString('expired', urldecode((string) $response->header('location')));
        self::assertNotNull($this->service(AccountRepository::class)->find($this->old->id));

        // Proved: the confirmation page, then the merge.
        $this->http->respond('GET', 'https://steele.example/', 301, '', ['Location' => 'https://www.steele.example/']);
        $response = $this->request('POST', '/settings/merge-account', post: ['old_domain' => 'steele.example', 'csrf' => $csrf]);
        self::assertSame(200, $response->status, $response->body);
        self::assertStringContainsString('Merge the account steele.example into this one?', $response->body);
        self::assertStringContainsString('<dd>1</dd>', $response->body);
        self::assertStringContainsString('action="/settings/merge-account/confirm"', $response->body);

        // The confirmation cannot be pointed at a different account.
        $other = $this->createAccount('other.example');
        $response = $this->request('POST', '/settings/merge-account/confirm', post: ['old_domain' => 'other.example', 'csrf' => $csrf]);
        self::assertStringContainsString('does not match', urldecode((string) $response->header('location')));
        self::assertNotNull($this->service(AccountRepository::class)->find($other->id));

        // Check again (the mismatch cleared the pending merge), then confirm properly.
        $this->request('POST', '/settings/merge-account', post: ['old_domain' => 'steele.example', 'csrf' => $csrf]);
        $response = $this->request('POST', '/settings/merge-account/confirm', post: ['old_domain' => 'steele.example', 'csrf' => $csrf]);
        self::assertSame(303, $response->status);
        self::assertStringContainsString('Merged the account steele.example: 1 site and 1 webmention', urldecode((string) $response->header('location')));
        self::assertStringStartsWith('/settings/sites?account_merged=', (string) $response->header('location'));
        self::assertNull($this->service(AccountRepository::class)->find($this->old->id));
        self::assertNotNull($this->service(SiteRepository::class)->findByAccountAndDomain($this->new->id, 'steele.example'));

        // CSRF is required on both steps.
        self::assertSame(403, $this->request('POST', '/settings/merge-account', post: ['old_domain' => 'x.example'])->status);
        self::assertSame(403, $this->request('POST', '/settings/merge-account/confirm', post: ['old_domain' => 'x.example'])->status);
    }
}
