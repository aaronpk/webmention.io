<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Model\Account;
use Webmention\Model\Site;
use Webmention\Storage\BlockRepository;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\MuteRepository;
use Webmention\Storage\SiteRepository;
use Webmention\Tests\Support\IntegrationTestCase;

/**
 * Holding mentions for review, muting, and the record of deletions
 * (issues 160, 85, 197, 128).
 */
final class ModerationTest extends IntegrationTestCase
{
    private const TARGET = 'http://target.example.com/entry';
    private const HOOK   = 'https://hooks.example.net/webmention';

    private Account $account;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = $this->createAccount('target.example.com');
        $this->site    = $this->createSite($this->account, 'target.example.com', ['callback_url' => self::HOOK, 'callback_secret' => 's3cret']);
    }

    public function testHoldEverythingUntilApproved(): void
    {
        $this->setPolicy('all');
        $this->source('http://commenter.example/reply', 'Casey Commenter', 'A thoughtful reply, longer than a tweet but not by much.');

        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => 'http://commenter.example/reply', 'target' => self::TARGET, 'debug' => '1']);
        self::assertSame(200, $response->status, $response->body);
        self::assertSame('success', self::json($response)['status'], 'the sender is not told about moderation');

        $link = $this->service(LinkRepository::class)->pendingForAccount($this->account->id, 10)[0];
        self::assertSame('pending', $link->status);
        self::assertFalse($link->verified);

        // Invisible everywhere a reader looks.
        self::assertSame([], self::json($this->request('GET', '/api/mentions.jf2', ['target' => self::TARGET]))['children']);
        self::assertSame(0, self::json($this->request('GET', '/api/count', ['target' => self::TARGET]))['count']);
        self::assertSame([], $this->service(LinkRepository::class)->recentForAccount($this->account->id, 10));
        self::assertSame([], $this->http->posts(self::HOOK), 'no web hook while held');

        // Shown to the owner, formatted, with the count in the nav.
        $csrf = $this->signIn($this->account);
        $dashboard = $this->request('GET', '/dashboard')->body;
        self::assertStringContainsString('Awaiting review', $dashboard);
        self::assertStringContainsString('<strong>Casey Commenter</strong>', $dashboard);
        self::assertStringContainsString('replied to', $dashboard);
        self::assertStringContainsString('<span class="muted">target.example.com</span>/entry</a>', $dashboard);
        self::assertStringContainsString('A thoughtful reply, longer than a tweet', $dashboard);
        self::assertStringContainsString('<span class="count" title="Awaiting review">1</span>', $dashboard);
        self::assertStringContainsString('action="/approve"', $dashboard);

        $approved = $this->request('POST', '/approve', post: ['id' => (string) $link->id, 'back' => '/dashboard', 'csrf' => $csrf]);
        self::assertSame(303, $approved->status);
        self::assertStringContainsString(rawurlencode('1 webmention approved.'), (string) $approved->header('location'));

        $now = $this->service(LinkRepository::class)->find($link->id);
        self::assertTrue($now?->verified);
        self::assertNull($now?->status);
        self::assertCount(1, self::json($this->request('GET', '/api/mentions.jf2', ['target' => self::TARGET]))['children']);
        $hooks = $this->http->posts(self::HOOK);
        self::assertCount(1, $hooks, 'the web hook fires on approval');
        self::assertSame('Casey Commenter', json_decode((string) $hooks[0]['body'], true)['post']['author']['name']);
        self::assertStringNotContainsString('Awaiting review', $this->request('GET', '/dashboard')->body);
    }

    public function testFirstTimeSendersAreHeldUntilOneIsApproved(): void
    {
        $this->setPolicy('first');
        $links = $this->service(LinkRepository::class);
        $this->source('http://new.example/one', 'New One', 'first');
        $this->source('http://new.example/two', 'New Two', 'second');
        $this->source('http://new.example/three', 'New Three', 'third');

        foreach (['one', 'two'] as $path) {
            $this->request('POST', '/target.example.com/webmention', post: ['source' => "http://new.example/$path", 'target' => self::TARGET, 'debug' => '1']);
        }
        self::assertSame(2, $links->countPendingForAccount($this->account->id));

        // Approving everything from the domain publishes both and fires both hooks...
        $csrf = $this->signIn($this->account);
        $this->request('POST', '/approve', post: ['domain' => 'new.example', 'back' => '/moderation', 'csrf' => $csrf]);
        self::assertSame(0, $links->countPendingForAccount($this->account->id));
        self::assertCount(2, $this->http->posts(self::HOOK));

        // ...and the domain is now trusted: the third goes straight through.
        $this->request('POST', '/target.example.com/webmention', post: ['source' => 'http://new.example/three', 'target' => self::TARGET, 'debug' => '1']);
        self::assertSame(0, $links->countPendingForAccount($this->account->id));
        self::assertCount(3, $this->http->posts(self::HOOK));

        // A published mention is never re-held, even under "all".
        $this->setPolicy('all');
        $this->redis->flushDb();
        $this->request('POST', '/target.example.com/webmention', post: ['source' => 'http://new.example/one', 'target' => self::TARGET, 'debug' => '1']);
        self::assertSame(0, $links->countPendingForAccount($this->account->id));
        self::assertCount(3, self::json($this->request('GET', '/api/mentions.jf2', ['target' => self::TARGET]))['children']);
    }

    public function testRejectDeletesAndBlocksWithoutAWebHook(): void
    {
        $this->setPolicy('all');
        $this->source('http://spam.example/buy', 'Spammer', 'buy now');
        $this->request('POST', '/target.example.com/webmention', post: ['source' => 'http://spam.example/buy', 'target' => self::TARGET, 'debug' => '1']);
        $link = $this->service(LinkRepository::class)->pendingForAccount($this->account->id, 10)[0];

        // Someone else cannot act on it.
        $mallory = $this->createAccount('mallory.example');
        $this->request('POST', '/reject', post: ['id' => (string) $link->id, 'csrf' => $this->signIn($mallory)]);
        $this->request('POST', '/approve', post: ['id' => (string) $link->id, 'csrf' => $this->signIn($mallory)]);
        self::assertSame('pending', $this->service(LinkRepository::class)->find($link->id)?->status);

        $csrf = $this->signIn($this->account);
        $this->request('POST', '/reject', post: ['id' => (string) $link->id, 'back' => '/dashboard', 'csrf' => $csrf]);

        self::assertTrue($this->service(LinkRepository::class)->find($link->id)?->deleted);
        self::assertTrue($this->service(BlockRepository::class)->isSourceBlocked($this->site->id, 'http://spam.example/buy'));
        self::assertSame([], $this->http->posts(self::HOOK));

        $this->redis->flushDb();
        $again = $this->request('POST', '/target.example.com/webmention', post: ['source' => 'http://spam.example/buy', 'target' => self::TARGET, 'debug' => '1']);
        self::assertSame('blocked', self::json($again)['error']);
    }

    public function testMutingHidesWithoutDeletingAndUnmutingRestores(): void
    {
        $links = $this->service(LinkRepository::class);
        $a = $this->createLink($this->site, self::TARGET, 'https://social.example/@spammer/1', ['author_url' => 'https://social.example/@spammer', 'author_name' => 'Spammer']);
        $b = $this->createLink($this->site, self::TARGET, 'https://bridge.example/post/2', ['author_url' => 'https://social.example/@spammer', 'author_name' => 'Spammer']);
        $c = $this->createLink($this->site, self::TARGET, 'https://bridge.example/post/3', ['author_url' => 'https://social.example/@friend', 'author_name' => 'Friend']);
        $d = $this->createLink($this->site, self::TARGET, 'https://sub.noisy.example/x', ['author_url' => 'https://noisy.example/', 'author_name' => 'Noisy']);
        $e = $this->createLink($this->site, self::TARGET, 'https://quiet.example/y', ['author_url' => 'https://quiet.example/', 'author_name' => 'Quiet']);
        $visible = fn (): array => array_column(self::json($this->request('GET', '/api/mentions.jf2', ['target' => self::TARGET]))['children'], 'wm-id');
        self::assertCount(5, $visible());

        $csrf = $this->signIn($this->account);

        // An author URL prefix: both of the spammer's, wherever they were relayed from.
        $mute = $this->request('POST', '/mute', post: ['kind' => 'author', 'pattern' => 'https://social.example/@spammer', 'csrf' => $csrf]);
        self::assertStringContainsString(rawurlencode('2 existing webmentions hidden'), (string) $mute->header('location'));
        self::assertEqualsCanonicalizing([$c, $d, $e], $visible());
        self::assertSame('hidden', $links->find($a)?->status);
        self::assertFalse($links->find($a)?->deleted);

        // A source domain covers its subdomains.
        $this->request('POST', '/mute', post: ['kind' => 'source', 'pattern' => 'noisy.example', 'csrf' => $csrf]);
        self::assertEqualsCanonicalizing([$c, $e], $visible());

        // A second rule overlapping the first: unmuting one keeps what the other still covers.
        $this->request('POST', '/mute', post: ['kind' => 'source', 'pattern' => 'social.example', 'csrf' => $csrf]);
        $rules = $this->service(MuteRepository::class)->forAccount($this->account->id);
        self::assertCount(3, $rules);
        $authorRule = array_values(array_filter($rules, static fn ($r): bool => $r->kind === 'author'))[0];

        $unmute = $this->request('POST', '/unmute-rule', post: ['id' => (string) $authorRule->id, 'csrf' => $csrf]);
        self::assertStringContainsString(rawurlencode('1 webmention visible again'), (string) $unmute->header('location'));
        self::assertEqualsCanonicalizing([$b, $c, $e], $visible(), 'a is still covered by the social.example source rule');

        // New mentions matching a rule arrive hidden, with no web hook, and the sender still sees success.
        $this->source('https://deep.noisy.example/z', 'Noisy Again', 'hello');
        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => 'https://deep.noisy.example/z', 'target' => self::TARGET, 'debug' => '1']);
        self::assertSame('success', self::json($response)['status']);
        self::assertSame('hidden', $links->recentForAccount($this->account->id, 1)[0]->status ?? $links->find((int) $this->db->value('SELECT MAX(id) FROM links'))?->status);
        self::assertSame([], $this->http->posts(self::HOOK));
        self::assertEqualsCanonicalizing([$b, $c, $e], $visible());

        // The rules are listed and described.
        $blocks = $this->request('GET', '/settings/blocks')->body;
        self::assertStringContainsString('Source on noisy.example', $blocks);
        self::assertStringContainsString('Source on social.example', $blocks);
        self::assertStringNotContainsString('Author URLs starting with', $blocks);

        // Garbage patterns are refused.
        $bad = $this->request('POST', '/mute', post: ['kind' => 'author', 'pattern' => 'not a domain', 'csrf' => $csrf]);
        self::assertStringContainsString(rawurlencode('Enter a domain name'), (string) $bad->header('location'));
    }

    public function testReviewQueueIsPaged(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->createLink($this->site, self::TARGET, "https://many.example/$i", ['verified' => 0, 'status' => 'pending', 'created_at' => sprintf('2026-03-%02d %02d:00:00', 1 + intdiv($i, 24), $i % 24)]);
        }
        $this->signIn($this->account);

        $dashboard = $this->request('GET', '/dashboard')->body;
        self::assertSame(20, substr_count($dashboard, '<form action="/reject"'));
        self::assertStringContainsString('See all 55 waiting', $dashboard);

        $first = $this->request('GET', '/moderation')->body;
        self::assertSame(50, substr_count($first, '<form action="/reject"'));
        self::assertStringContainsString('Page 1 of 2', $first);
        self::assertStringContainsString('https://many.example/55', $first);

        $second = $this->request('GET', '/moderation', ['page' => '1'])->body;
        self::assertSame(5, substr_count($second, '<form action="/reject"'));
        self::assertStringContainsString('https://many.example/1<', $second);
    }

    public function testDeletedMentionsAreReported(): void
    {
        $kept    = $this->createLink($this->site, self::TARGET, 'https://a.example/1');
        $gone    = $this->createLink($this->site, self::TARGET, 'https://b.example/2', ['deleted' => 1, 'updated_at' => '2026-02-01 00:00:00']);
        $older   = $this->createLink($this->site, self::TARGET, 'https://c.example/3', ['deleted' => 1, 'updated_at' => '2026-01-01 00:00:00']);
        $private = $this->createLink($this->site, self::TARGET, 'https://p.example/4', ['deleted' => 1, 'is_private' => 1, 'updated_at' => '2026-03-01 00:00:00']);
        $token   = $this->service(\Webmention\Storage\AccountRepository::class)->regenerateToken($this->account->id);

        $byTarget = self::json($this->request('GET', '/api/deleted', ['target' => self::TARGET]));
        self::assertSame('feed', $byTarget['type']);
        self::assertSame([$gone, $older], array_column($byTarget['children'], 'wm-id'), 'newest deletion first, private left out');
        self::assertSame(['wm-id', 'wm-source', 'wm-target', 'wm-deleted'], array_keys($byTarget['children'][0]));
        self::assertSame('2026-02-01T00:00:00Z', $byTarget['children'][0]['wm-deleted']);
        self::assertSame(self::TARGET, $byTarget['children'][0]['wm-target']);

        self::assertSame([$gone], array_column(self::json($this->request('GET', '/api/deleted.jf2', ['target' => self::TARGET, 'since' => '2026-01-15T00:00:00Z']))['children'], 'wm-id'));
        self::assertSame([$private, $gone, $older], array_column(self::json($this->request('GET', '/api/deleted', ['token' => $token]))['children'], 'wm-id'));
        self::assertSame(400, $this->request('GET', '/api/deleted')->status);
        self::assertSame('*', $this->request('GET', '/api/deleted', ['target' => self::TARGET])->header('access-control-allow-origin'));

        // Deleting from the dashboard shows up too.
        $csrf = $this->signIn($this->account);
        $this->request('POST', '/delete', post: ['id' => (string) $kept, 'csrf' => $csrf]);
        self::assertContains($kept, array_column(self::json($this->request('GET', '/api/deleted', ['target' => self::TARGET]))['children'], 'wm-id'));
    }

    private function setPolicy(string $policy): void
    {
        $this->service(SiteRepository::class)->updateWebhook($this->site->id, self::HOOK, 's3cret', true, $policy);
        $this->site = $this->service(SiteRepository::class)->find($this->site->id);
    }

    /** A source page replying to the target, served by the fake transport. */
    private function source(string $url, string $author, string $text): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        $this->http->respond('GET', $url, 200, '<div class="h-entry"><a class="u-in-reply-to" href="' . self::TARGET . '">re</a><p class="e-content">' . $text . '</p>'
            . '<div class="p-author h-card"><span class="p-name">' . $author . '</span><a class="u-url" href="https://' . $host . '/">home</a></div></div>', ['Content-Type' => 'text/html']);
    }
}
