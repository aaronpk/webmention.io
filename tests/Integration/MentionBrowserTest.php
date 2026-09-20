<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Controllers\ReturnPath;
use Webmention\Model\Account;
use Webmention\Model\Site;
use Webmention\Storage\BlockRepository;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\MuteRepository;
use Webmention\Tests\Support\IntegrationTestCase;

/**
 * The mention browser at /mentions: filters, the four states, restoring,
 * and approving or rejecting several at once.
 */
final class MentionBrowserTest extends IntegrationTestCase
{
    private const HOOK = 'https://hooks.example.net/webmention';

    private Account $alice;
    private Site $blog;
    private Site $photos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice  = $this->createAccount('alice.example');
        $this->blog   = $this->createSite($this->alice, 'alice.example', ['callback_url' => self::HOOK, 'callback_secret' => 's3cret']);
        $this->photos = $this->createSite($this->alice, 'photos.alice.example');
    }

    public function testFiltersNarrowBySiteKindAndSourceDomain(): void
    {
        $this->createLink($this->blog, 'https://alice.example/post', 'https://bob.example/reply', ['type' => 'reply', 'created_at' => '2026-03-01 10:00:00']);
        $this->createLink($this->blog, 'https://alice.example/post', 'https://bob.example/like', ['type' => 'like', 'created_at' => '2026-03-02 10:00:00']);
        $this->createLink($this->photos, 'https://photos.alice.example/1', 'https://carol.example/rsvp', ['type' => 'rsvp-yes', 'created_at' => '2026-03-03 10:00:00']);
        $this->createLink($this->photos, 'https://photos.alice.example/1', 'https://carol.example/plain', ['type' => 'link', 'created_at' => '2026-03-04 10:00:00']);
        $mallory = $this->createAccount('mallory.example');
        $this->createLink($this->createSite($mallory, 'mallory.example'), 'https://mallory.example/x', 'https://bob.example/other');
        $this->signIn($this->alice);

        $all = $this->request('GET', '/mentions');
        self::assertSame(200, $all->status);
        self::assertStringContainsString('4 published webmentions.', $all->body);
        self::assertStringContainsString('<label for="menu" class="menu-button">', $all->body, 'the collapsing menu, for narrow screens');
        self::assertStringContainsString('<nav class="nav" id="account-nav"', $all->body);
        self::assertMatchesRegularExpression('#<link rel="stylesheet" href="/assets/app\.css\?v=\d{10}">#', $all->body, 'assets carry their mtime, against stale caches');
        self::assertMatchesRegularExpression('#<script src="/assets/app\.js\?v=\d{10}"></script>#', $all->body);
        self::assertStringContainsString('<a href="/mentions" aria-current="page">Mentions</a>', $all->body);
        self::assertStringNotContainsString('bob.example/other', $all->body, "another account's mention");
        self::assertStringContainsString('<option value="' . $this->photos->id . '">photos.alice.example</option>', $all->body);
        // Newest first.
        self::assertLessThan(strpos($all->body, 'bob.example/reply'), strpos($all->body, 'carol.example/plain'));

        $body = $this->request('GET', '/mentions', ['site' => (string) $this->photos->id])->body;
        self::assertStringContainsString('2 published webmentions to photos.alice.example.', $body);
        self::assertStringNotContainsString('bob.example/reply', $body);

        $body = $this->request('GET', '/mentions', ['type' => 'rsvp'])->body;
        self::assertStringContainsString('1 published webmention.', $body);
        self::assertStringContainsString('carol.example/rsvp', $body);

        $body = $this->request('GET', '/mentions', ['type' => 'mention'])->body;
        self::assertStringContainsString('carol.example/plain', $body);
        self::assertStringNotContainsString('carol.example/rsvp', $body);

        $body = $this->request('GET', '/mentions', ['domain' => 'https://BOB.example/anything'])->body;
        self::assertStringContainsString('2 published webmentions from bob.example.', $body);
        self::assertStringContainsString('value="bob.example"', $body, 'the filter field shows the cleaned-up value');
        self::assertStringContainsString('href="/mentions">Clear</a>', $body);

        $body = $this->request('GET', '/mentions', ['domain' => 'bob.example', 'type' => 'like', 'site' => (string) $this->blog->id])->body;
        self::assertStringContainsString('1 published webmention from bob.example to alice.example.', $body);
        self::assertStringContainsString('bob.example/like', $body);
        self::assertStringNotContainsString('bob.example/reply', $body);

        // Nonsense filters are ignored rather than failing.
        $body = $this->request('GET', '/mentions', ['site' => '999', 'type' => 'bogus', 'status' => 'nope', 'domain' => 'not a host'])->body;
        self::assertStringContainsString('4 published webmentions.', $body);
    }

    public function testEachStateIsItsOwnListAndHiddenRowsNameTheirRule(): void
    {
        $this->createLink($this->blog, 'https://alice.example/post', 'https://bob.example/published');
        $this->createLink($this->blog, 'https://alice.example/post', 'https://bob.example/pending', ['verified' => 0, 'status' => 'pending']);
        $this->createLink($this->blog, 'https://alice.example/post', 'https://spam.example/hidden', ['verified' => 0, 'status' => 'hidden']);
        $this->createLink($this->blog, 'https://alice.example/post', 'https://bob.example/deleted', ['deleted' => 1, 'updated_at' => '2026-03-09 12:00:00']);
        $this->service(MuteRepository::class)->add($this->alice->id, 'source', 'spam.example');
        $this->signIn($this->alice);

        $expected = [
            'published' => ['bob.example/published', '1 published webmention.'],
            'pending'   => ['bob.example/pending', '1 awaiting review webmention.'],
            'hidden'    => ['spam.example/hidden', '1 hidden webmention.'],
            'deleted'   => ['bob.example/deleted', '1 deleted webmention.'],
        ];
        foreach ($expected as $status => [$source, $count]) {
            $body = $this->request('GET', '/mentions', ['status' => $status])->body;
            self::assertStringContainsString($source, $body, $status);
            self::assertStringContainsString($count, $body, $status);
            foreach ($expected as $other => [$otherSource]) {
                if ($other !== $status) {
                    self::assertStringNotContainsString($otherSource, $body, "$status shows no $other rows");
                }
            }
            self::assertStringContainsString('aria-current="page">' . ['published' => 'Published', 'pending' => 'Awaiting review', 'hidden' => 'Hidden', 'deleted' => 'Deleted'][$status] . '</a>', $body);
        }

        $hidden = $this->request('GET', '/mentions', ['status' => 'hidden'])->body;
        self::assertStringContainsString('hidden by the rule "Source on spam.example"', $hidden);
        self::assertStringContainsString('<button type="submit" class="secondary small" title="Publish this webmention; the mute rule stays">Show</button>', $hidden);

        $deleted = $this->request('GET', '/mentions', ['status' => 'deleted'])->body;
        self::assertStringContainsString('deleted Mar 9, 2026', $deleted);
        self::assertStringContainsString('action="/restore"', $deleted);
        self::assertStringNotContainsString('aria-label="Delete"', $deleted);

        $pending = $this->request('GET', '/mentions', ['status' => 'pending'])->body;
        self::assertStringContainsString('id="bulk"', $pending);
        self::assertStringContainsString('name="id[]"', $pending);
        self::assertStringContainsString('name="back" value="/mentions?status=pending"', $pending);
    }

    public function testShowingAHiddenMentionPublishesItAndFiresTheWebHookOnce(): void
    {
        $id = $this->createLink($this->blog, 'https://alice.example/post', 'https://spam.example/hidden', ['verified' => 0, 'status' => 'hidden']);
        $this->service(MuteRepository::class)->add($this->alice->id, 'source', 'spam.example');
        $csrf = $this->signIn($this->alice);

        $response = $this->request('POST', '/approve', post: ['id' => (string) $id, 'back' => '/mentions?status=hidden', 'csrf' => $csrf]);
        self::assertSame(303, $response->status);
        self::assertSame('/mentions?status=hidden', $response->header('location'));
        self::assertStringContainsString('<p class="notice">1 webmention approved.</p>', $this->request('GET', '/mentions', ['status' => 'hidden'])->body);

        $link = $this->service(LinkRepository::class)->find($id);
        self::assertTrue($link?->verified);
        self::assertNull($link?->status);
        self::assertCount(1, $this->http->posts(self::HOOK));
        self::assertCount(1, $this->service(MuteRepository::class)->forAccount($this->alice->id), 'the rule stays');
        self::assertStringContainsString('spam.example/hidden', $this->request('GET', '/mentions')->body);
    }

    public function testRestoringADeletedMentionUnblocksItsSourceAndNotifies(): void
    {
        $id = $this->createLink($this->blog, 'https://alice.example/post', 'https://bob.example/reply');
        $csrf = $this->signIn($this->alice);

        // Delete it from the dashboard: deleted, blocked, and the hook told.
        $this->request('POST', '/delete', post: ['id' => (string) $id, 'csrf' => $csrf]);
        $blocks = $this->service(BlockRepository::class);
        self::assertTrue($blocks->isSourceBlocked($this->blog->id, 'https://bob.example/reply'));
        self::assertCount(1, $this->http->posts(self::HOOK));
        self::assertTrue(json_decode((string) $this->http->posts(self::HOOK)[0]['body'], true)['deleted']);

        $response = $this->request('POST', '/restore', post: ['id' => (string) $id, 'back' => '/mentions?status=deleted&page=0', 'csrf' => $csrf]);
        self::assertSame('/mentions?status=deleted', $response->header('location'));
        self::assertStringContainsString('<p class="notice">Webmention restored; its source URL is unblocked.</p>', $this->request('GET', '/mentions', ['status' => 'deleted'])->body);

        $link = $this->service(LinkRepository::class)->find($id);
        self::assertFalse($link?->deleted);
        self::assertTrue($link?->verified);
        self::assertNull($link?->status);
        self::assertFalse($blocks->isSourceBlocked($this->blog->id, 'https://bob.example/reply'));
        $hooks = $this->http->posts(self::HOOK);
        self::assertCount(2, $hooks);
        $payload = json_decode((string) $hooks[1]['body'], true);
        self::assertArrayNotHasKey('deleted', $payload);
        self::assertSame('https://bob.example/reply', $payload['source']);
        self::assertCount(1, self::json($this->request('GET', '/api/mentions.jf2', ['target' => 'https://alice.example/post']))['children']);

        // Restoring again, or restoring a live mention, changes nothing.
        $again = $this->request('POST', '/restore', post: ['id' => (string) $id, 'csrf' => $csrf]);
        self::assertSame('/dashboard', $again->header('location'));
        self::assertStringContainsString('That webmention is not deleted.', $this->request('GET', '/dashboard')->body);
        self::assertCount(2, $this->http->posts(self::HOOK));

        // A domain block stays, and the notice says so.
        $this->request('POST', '/delete', post: ['domain' => 'bob.example', 'csrf' => $csrf]);
        $response = $this->request('POST', '/restore', post: ['id' => (string) $id, 'csrf' => $csrf]);
        self::assertSame('/dashboard', $response->header('location'));
        self::assertStringContainsString('Its domain, bob.example, is still blocked.', $this->request('GET', '/dashboard')->body);
        self::assertTrue($blocks->isDomainBlocked($this->alice->id, 'bob.example'));
    }

    public function testAnotherAccountsMentionCannotBeRestoredOrShown(): void
    {
        $mallory = $this->createAccount('mallory.example');
        $theirs  = $this->createLink($this->createSite($mallory, 'mallory.example'), 'https://mallory.example/x', 'https://bob.example/other', ['deleted' => 1]);
        $csrf    = $this->signIn($this->alice);

        $this->request('POST', '/restore', post: ['id' => (string) $theirs, 'csrf' => $csrf]);
        self::assertTrue($this->service(LinkRepository::class)->find($theirs)?->deleted);
        $this->request('POST', '/approve', post: ['id' => (string) $theirs, 'csrf' => $csrf]);
        self::assertTrue($this->service(LinkRepository::class)->find($theirs)?->deleted);
    }

    public function testBulkApproveAndRejectSkipWhatIsNotYoursOrNotWaiting(): void
    {
        $mine = [];
        for ($i = 1; $i <= 4; $i++) {
            $mine[] = $this->createLink($this->blog, 'https://alice.example/post', "https://bob.example/$i", ['verified' => 0, 'status' => 'pending']);
        }
        $live    = $this->createLink($this->blog, 'https://alice.example/post', 'https://bob.example/live');
        $mallory = $this->createAccount('mallory.example');
        $theirs  = $this->createLink($this->createSite($mallory, 'mallory.example'), 'https://mallory.example/x', 'https://bob.example/theirs', ['verified' => 0, 'status' => 'pending']);
        $csrf    = $this->signIn($this->alice);

        $response = $this->request('POST', '/approve', post: ['id' => [(string) $mine[0], (string) $mine[1], (string) $theirs, (string) $live, 'abc', (string) $mine[0]], 'back' => '/moderation?page=0', 'csrf' => $csrf]);
        self::assertSame('/moderation', $response->header('location'));
        self::assertStringContainsString('2 webmentions approved.', $this->request('GET', '/dashboard')->body, 'the notice key is shared by the review pages');
        $links = $this->service(LinkRepository::class);
        self::assertNull($links->find($mine[0])?->status);
        self::assertNull($links->find($mine[1])?->status);
        self::assertSame('pending', $links->find($mine[2])?->status);
        self::assertSame('pending', $links->find($theirs)?->status);
        self::assertCount(2, $this->http->posts(self::HOOK), 'one web hook per approved mention');

        $response = $this->request('POST', '/reject', post: ['id' => [(string) $mine[2], (string) $mine[3], (string) $theirs, (string) $live], 'back' => '/mentions?status=pending&type=reply', 'csrf' => $csrf]);
        self::assertSame('/mentions?status=pending&type=reply', $response->header('location'));
        self::assertStringContainsString('2 webmentions rejected; their source URLs are blocked.', $this->request('GET', '/mentions', ['status' => 'pending', 'type' => 'reply'])->body);
        self::assertTrue($links->find($mine[2])?->deleted);
        self::assertTrue($links->find($mine[3])?->deleted);
        self::assertFalse($links->find($live)?->deleted, 'a published mention is not rejected');
        self::assertFalse($links->find($theirs)?->deleted);
        self::assertTrue($this->service(BlockRepository::class)->isSourceBlocked($this->blog->id, 'https://bob.example/3'));
        self::assertCount(2, $this->http->posts(self::HOOK), 'rejecting sends nothing');

        // Nothing left: the old message, and one id still reads as before.
        $response = $this->request('POST', '/reject', post: ['id' => (string) $mine[2], 'csrf' => $csrf]);
        self::assertSame('/dashboard', $response->header('location'));
        self::assertStringContainsString('That webmention is no longer waiting for review.', $this->request('GET', '/dashboard')->body);
    }

    public function testBulkRequestsAreCapped(): void
    {
        $ids = [];
        for ($i = 1; $i <= 205; $i++) {
            $ids[] = (string) $this->createLink($this->blog, 'https://alice.example/post', "https://bob.example/$i", ['verified' => 0, 'status' => 'pending']);
        }
        $csrf = $this->signIn($this->alice);

        $response = $this->request('POST', '/approve', post: ['id' => $ids, 'csrf' => $csrf]);
        self::assertSame('/dashboard', $response->header('location'));
        self::assertStringContainsString('200 webmentions approved.', $this->request('GET', '/dashboard')->body);
        self::assertSame(5, $this->service(LinkRepository::class)->countPendingForAccount($this->alice->id));
    }

    public function testPagingAndTheReturnPath(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->createLink($this->blog, 'https://alice.example/post', "https://many.example/$i", ['created_at' => sprintf('2026-03-%02d %02d:00:00', 1 + intdiv($i, 24), $i % 24)]);
        }
        $this->signIn($this->alice);

        $first = $this->request('GET', '/mentions', ['domain' => 'many.example'])->body;
        self::assertSame(50, substr_count($first, 'class="mention-row'));
        self::assertStringContainsString('Page 1 of 2', $first);
        self::assertStringContainsString('href="/mentions?domain=many.example&amp;page=1">Older', $first);
        self::assertStringContainsString('https://many.example/55', $first);

        $second = $this->request('GET', '/mentions', ['domain' => 'many.example', 'page' => '7'])->body;
        self::assertSame(5, substr_count($second, 'class="mention-row'), 'a page past the end is the last page');
        self::assertStringContainsString('https://many.example/1<', $second);
        self::assertStringContainsString('href="/mentions?domain=many.example">&larr; Newer', $second);

        self::assertSame('/mentions?status=pending&page=2', ReturnPath::resolve('/mentions?status=pending&page=2'));
        self::assertSame('/mentions?status=pending', ReturnPath::resolve('/mentions?status=pending&evil=1'));
        self::assertSame('/moderation?page=3', ReturnPath::resolve('/moderation?page=3'));
        self::assertSame('/dashboard', ReturnPath::resolve('https://evil.example/mentions'));
        self::assertSame('/dashboard', ReturnPath::resolve('/mentions?status=pending#x'));
        self::assertSame('/dashboard', ReturnPath::resolve('//evil.example/'));
        self::assertSame('/dashboard', ReturnPath::resolve(null));
        self::assertSame('/settings/blocks', ReturnPath::resolve('', '/settings/blocks'));
    }

    public function testTheApiStillListsOnlyPublishedMentions(): void
    {
        $this->createLink($this->blog, 'https://alice.example/post', 'https://bob.example/published');
        $this->createLink($this->blog, 'https://alice.example/post', 'https://bob.example/pending', ['verified' => 0, 'status' => 'pending']);
        $this->createLink($this->blog, 'https://alice.example/post', 'https://bob.example/hidden', ['verified' => 0, 'status' => 'hidden']);
        $this->createLink($this->blog, 'https://alice.example/post', 'https://bob.example/deleted', ['deleted' => 1]);

        $children = self::json($this->request('GET', '/api/mentions.jf2', ['target' => 'https://alice.example/post']))['children'];
        self::assertSame(['https://bob.example/published'], array_column($children, 'wm-source'));
        self::assertSame(1, self::json($this->request('GET', '/api/count', ['target' => 'https://alice.example/post']))['count']);
    }
}
