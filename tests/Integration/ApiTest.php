<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Model\Account;
use Webmention\Model\Site;
use Webmention\Storage\AccountRepository;
use Webmention\Tests\Support\IntegrationTestCase;

final class ApiTest extends IntegrationTestCase
{
    private const TARGET = 'https://example.com/post';

    private Account $account;
    private Site $site;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = $this->createAccount('example.com');
        $this->site    = $this->createSite($this->account, 'example.com');
        $this->token   = $this->service(AccountRepository::class)->regenerateToken($this->account->id);

        $this->createLink($this->site, self::TARGET, 'https://a.example/1', ['type' => 'like', 'created_at' => '2020-01-01 00:00:00']);
        $this->createLink($this->site, self::TARGET, 'https://b.example/2', ['type' => 'rsvp-no', 'created_at' => '2020-01-02 00:00:00']);
        $this->createLink($this->site, self::TARGET, 'https://c.example/3', ['type' => 'rsvp-yes', 'created_at' => '2020-01-03 00:00:00']);
        $this->createLink($this->site, self::TARGET, 'https://d.example/4', ['type' => 'link', 'created_at' => '2020-01-04 00:00:00']);
        $this->createLink($this->site, self::TARGET, 'https://e.example/5', ['type' => null, 'created_at' => '2020-01-05 00:00:00']);
        $this->createLink($this->site, self::TARGET, 'https://invite.example/10', ['type' => 'invite', 'created_at' => '2020-01-06 00:00:00']);
        $this->createLink($this->site, self::TARGET, 'https://post.example/11', ['type' => 'post', 'created_at' => '2020-01-07 00:00:00']);
        $this->createLink($this->site, self::TARGET, 'https://deleted.example/6', ['deleted' => 1]);
        $this->createLink($this->site, self::TARGET, 'https://unverified.example/7', ['verified' => 0]);
        $this->createLink($this->site, self::TARGET, 'https://private.example/9', ['type' => 'reply', 'is_private' => 1, 'content' => '<p>Just between us</p>', 'created_at' => '2019-12-01 00:00:00']);
        $this->createLink($this->site, 'https://example.com/other', 'https://f.example/8', [
            'type'        => 'reply',
            'author_name' => '<script>alert(1)</script>',
            'author_url'  => 'javascript:alert(1)',
            'content'     => '<p>Hello</p>',
            'created_at'  => '2020-02-01 00:00:00',
        ]);
    }

    public function testCount(): void
    {
        $response = $this->request('GET', '/api/count', ['target' => self::TARGET]);

        // Seven verified public links; the breakdown adds up to the total, with untyped rows counted as mentions.
        self::assertSame('{"count":7,"type":{"invite":1,"like":1,"mention":2,"post":1,"rsvp-no":1,"rsvp-yes":1}}', $response->body);
        self::assertSame('application/json;charset=UTF-8', $response->header('content-type'));
    }

    public function testCountOfUnknownTargetHasAnEmptyTypeObject(): void
    {
        self::assertSame('{"count":0,"type":{}}', $this->request('GET', '/api/count.json', ['target' => 'https://nope.example/'])->body);
    }

    public function testMentionsByTarget(): void
    {
        $jf2 = self::json($this->request('GET', '/api/mentions.jf2', ['target' => self::TARGET]));

        self::assertSame('feed', $jf2['type']);
        self::assertSame(
            ['https://post.example/11', 'https://invite.example/10', 'https://e.example/5', 'https://d.example/4', 'https://c.example/3', 'https://b.example/2', 'https://a.example/1'],
            array_column($jf2['children'], 'wm-source'),
        );
    }

    public function testFiltersSortingAndPaging(): void
    {
        $sources = fn (array $query): array => array_column(self::json($this->request('GET', '/api/mentions', $query))['links'], 'source');

        self::assertSame(['https://c.example/3', 'https://b.example/2'], $sources(['target' => self::TARGET, 'wm-property' => 'rsvp']));
        self::assertSame(['https://a.example/1', 'https://d.example/4', 'https://e.example/5', 'https://invite.example/10', 'https://post.example/11'], $sources(['target' => self::TARGET, 'wm-property' => ['mention-of', 'like-of'], 'sort-dir' => 'up']));
        self::assertSame(['https://a.example/1', 'https://b.example/2'], $sources(['target' => self::TARGET, 'sort-dir' => 'up', 'per-page' => '2']));
        self::assertSame(['https://c.example/3', 'https://d.example/4'], $sources(['target' => self::TARGET, 'sort-dir' => 'up', 'per-page' => '2', 'page' => '1']));
        self::assertSame(['https://post.example/11', 'https://invite.example/10', 'https://e.example/5'], $sources(['target' => self::TARGET, 'since' => '2020-01-04T12:00:00+00:00']));
        self::assertSame(['https://c.example/3', 'https://b.example/2'], array_slice($sources(['target' => self::TARGET, 'sort-by' => 'rsvp']), 0, 2));
        self::assertSame(['https://a.example/1', 'https://b.example/2'], $sources(['target' => self::TARGET, 'sort-dir' => 'up', 'perPage' => '2', 'page' => '-3']));
    }

    public function testEveryJsonResponseSaysWhereItSitsInThePages(): void
    {
        $paging = fn (string $kind, array $query): array => self::json($this->request('GET', "/api/$kind", $query))['paging'];

        self::assertSame(['per-page' => 20, 'page' => 0, 'total' => 7, 'total-pages' => 1], $paging('mentions.jf2', ['target' => self::TARGET]));
        self::assertSame(['per-page' => 2, 'page' => 1, 'total' => 7, 'total-pages' => 4], $paging('mentions', ['target' => self::TARGET, 'per-page' => '2', 'page' => '1']));
        self::assertSame(['per-page' => 20, 'page' => 0, 'total' => 1, 'total-pages' => 1], $paging('mentions.jf2', ['target' => self::TARGET, 'wm-property' => 'like-of']));
        self::assertSame(['per-page' => 20, 'page' => 0, 'total' => 9, 'total-pages' => 1], $paging('mentions.jf2', ['token' => $this->token]), 'the owner sees the private one too');
        self::assertSame(['per-page' => 20, 'page' => 0, 'total' => 0, 'total-pages' => 0], $paging('mentions.jf2', ['token' => $this->token, 'domain' => 'nope.example']));
        self::assertSame(['per-page' => 5, 'page' => 0, 'total' => 21, 'total-pages' => 5], $paging('example/mentions.jf2', ['per-page' => '5']));

        // The list keeps its place as the first key, for clients that never look further.
        self::assertSame(['links', 'paging'], array_keys(self::json($this->request('GET', '/api/mentions', ['target' => self::TARGET]))));
        self::assertSame(['type', 'name', 'children', 'paging'], array_keys(self::json($this->request('GET', '/api/mentions.jf2', ['target' => self::TARGET]))));
    }

    public function testMentionOfMatchesEverythingShownAsAMention(): void
    {
        // Issue 206: rows with no type (and other unlabelled types) are shown as
        // mention-of, so the mention-of filter has to find them.
        $jf2 = self::json($this->request('GET', '/api/mentions.jf2', ['target' => self::TARGET, 'wm-property' => 'mention-of', 'sort-dir' => 'up']));

        self::assertSame(
            ['https://d.example/4', 'https://e.example/5', 'https://invite.example/10', 'https://post.example/11'],
            array_column($jf2['children'], 'wm-source'),
        );
        self::assertSame(['mention-of'], array_unique(array_column($jf2['children'], 'wm-property')));

        // The other filters are unchanged, in both formats.
        $like = self::json($this->request('GET', '/api/mentions', ['target' => self::TARGET, 'wm-property' => 'like-of']));
        self::assertSame(['https://a.example/1'], array_column($like['links'], 'source'));
        $rsvp = self::json($this->request('GET', '/api/mentions', ['target' => self::TARGET, 'wm-property' => 'rsvp', 'sort-dir' => 'up']));
        self::assertSame(['https://b.example/2', 'https://c.example/3'], array_column($rsvp['links'], 'source'));
    }

    public function testTokenAndDomain(): void
    {
        $all = self::json($this->request('GET', '/api/mentions.jf2', ['token' => $this->token]));
        self::assertCount(9, $all['children']);

        $domain = self::json($this->request('GET', '/api/mentions.jf2', ['token' => $this->token, 'domain' => 'example.com']));
        self::assertCount(9, $domain['children']);

        $bearer = self::json($this->request('GET', '/api/mentions.jf2', headers: ['authorization' => 'Bearer ' . $this->token]));
        self::assertCount(9, $bearer['children']);

        $unknown = self::json($this->request('GET', '/api/mentions.jf2', ['token' => $this->token, 'domain' => 'nope.example']));
        self::assertSame([], $unknown['children']);

        self::assertSame(401, $this->request('GET', '/api/mentions.jf2', ['token' => 'wrong'])->status);
        self::assertSame(400, $this->request('GET', '/api/mentions.jf2')->status);
    }

    public function testPrivateWebmentionsAreOnlyShownToTheirOwner(): void
    {
        $public = self::json($this->request('GET', '/api/mentions.jf2', ['target' => self::TARGET]));
        self::assertNotContains('https://private.example/9', array_column($public['children'], 'wm-source'));
        self::assertStringNotContainsString('Just between us', $this->request('GET', '/api/mentions.jf2', ['target' => self::TARGET, 'per-page' => '100'])->body);
        self::assertStringNotContainsString('Just between us', $this->request('GET', '/api/mentions.html', ['target' => self::TARGET])->body);
        self::assertStringNotContainsString('private.example', $this->request('GET', '/api/mentions.atom', ['target' => self::TARGET])->body);
        self::assertSame('{"count":7,"type":{"invite":1,"like":1,"mention":2,"post":1,"rsvp-no":1,"rsvp-yes":1}}', $this->request('GET', '/api/count', ['target' => self::TARGET])->body);

        $owner = self::json($this->request('GET', '/api/mentions.jf2', ['token' => $this->token, 'target' => self::TARGET]));
        // A target query is public even with a token; the account's own listing includes it.
        self::assertNotContains('https://private.example/9', array_column($owner['children'], 'wm-source'));
        $mine = self::json($this->request('GET', '/api/mentions.jf2', ['token' => $this->token]));
        self::assertContains('https://private.example/9', array_column($mine['children'], 'wm-source'));
        self::assertTrue(array_values(array_filter($mine['children'], static fn (array $c): bool => $c['wm-source'] === 'https://private.example/9'))[0]['wm-private']);
    }

    public function testPageSizeIsCapped(): void
    {
        self::assertSame(200, $this->request('GET', '/api/mentions', ['target' => self::TARGET, 'per-page' => '100000000'])->status);

        // Page 1 of an over-large page size starts at the cap, not at the absurd offset.
        $second = self::json($this->request('GET', '/api/mentions', ['token' => $this->token, 'per-page' => '100000000', 'page' => '1']));
        self::assertSame([], $second['links']);
        self::assertSame(1000, \Webmention\Controllers\ApiController::MAX_PER_PAGE);
    }

    public function testOverLongAndExcessTargetsAreIgnored(): void
    {
        $long = 'https://example.com/' . str_repeat('a', 600);
        self::assertSame(400, $this->request('GET', '/api/count', ['target' => $long])->status);

        // Duplicates are collapsed, so the excess has to be distinct URLs.
        $many   = array_map(static fn (int $i): string => "https://nope.example/$i", range(1, 59));
        $many[] = self::TARGET;
        self::assertSame('{"count":0,"type":{}}', $this->request('GET', '/api/count', ['target' => $many])->body);
    }

    public function testFeedsAreNotCacheable(): void
    {
        self::assertSame('no-store', $this->request('GET', '/api/mentions.atom', ['token' => $this->token])->header('cache-control'));
        self::assertSame('no-store', $this->request('GET', '/api/mentions.html', ['token' => $this->token])->header('cache-control'));
        self::assertStringContainsString("form-action 'none'", (string) $this->request('GET', '/api/mentions.html', ['token' => $this->token])->header('content-security-policy'));
    }

    public function testJsonp(): void
    {
        $response = $this->request('GET', '/api/count', ['target' => 'https://nope.example/', 'jsonp' => 'cb']);

        self::assertSame('cb({"count":0,"type":{}})', $response->body);
        self::assertSame('text/javascript;charset=UTF-8', $response->header('content-type'));
    }

    public function testHtmlFeedEscapesSourceDataAndDisallowsScript(): void
    {
        $response = $this->request('GET', '/api/mentions.html', ['token' => $this->token]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<h1 class="p-name">example.com</h1>', $response->body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $response->body);
        self::assertStringNotContainsString('javascript:', $response->body);
        self::assertStringContainsString('<div class="e-content html"><p>Hello</p></div>', $response->body);
        self::assertStringNotContainsString('script-src', (string) $response->header('content-security-policy'));
        self::assertStringNotContainsString('frame-ancestors', (string) $response->header('content-security-policy'));
    }

    public function testAtom(): void
    {
        $response = $this->request('GET', '/api/mentions.atom', ['target' => self::TARGET]);

        self::assertSame('application/atom+xml;charset=UTF-8', $response->header('content-type'));
        self::assertNotFalse(simplexml_load_string($response->body));
        self::assertStringContainsString('<title>e.example mentioned /post</title>', $response->body);
    }

    public function testErrorsInAtomAndHtmlFormatsAreJson(): void
    {
        $response = $this->request('GET', '/api/mentions.atom');

        self::assertSame(400, $response->status);
        self::assertSame('invalid_input', self::json($response)['error']);
    }

    public function testUnknownApiPathIs404(): void
    {
        self::assertSame(404, $this->request('GET', '/api/mentions.xml')->status);
    }
}
