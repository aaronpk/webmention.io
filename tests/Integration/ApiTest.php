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
        $this->createLink($this->site, self::TARGET, 'https://deleted.example/6', ['deleted' => 1]);
        $this->createLink($this->site, self::TARGET, 'https://unverified.example/7', ['verified' => 0]);
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

        self::assertSame('{"count":5,"type":{"like":1,"mention":1,"rsvp-no":1,"rsvp-yes":1}}', $response->body);
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
            ['https://e.example/5', 'https://d.example/4', 'https://c.example/3', 'https://b.example/2', 'https://a.example/1'],
            array_column($jf2['children'], 'wm-source'),
        );
    }

    public function testFiltersSortingAndPaging(): void
    {
        $sources = fn (array $query): array => array_column(self::json($this->request('GET', '/api/mentions', $query))['links'], 'source');

        self::assertSame(['https://c.example/3', 'https://b.example/2'], $sources(['target' => self::TARGET, 'wm-property' => 'rsvp']));
        self::assertSame(['https://a.example/1', 'https://d.example/4'], $sources(['target' => self::TARGET, 'wm-property' => ['mention-of', 'like-of'], 'sort-dir' => 'up']));
        self::assertSame(['https://a.example/1', 'https://b.example/2'], $sources(['target' => self::TARGET, 'sort-dir' => 'up', 'per-page' => '2']));
        self::assertSame(['https://c.example/3', 'https://d.example/4'], $sources(['target' => self::TARGET, 'sort-dir' => 'up', 'per-page' => '2', 'page' => '1']));
        self::assertSame(['https://e.example/5'], $sources(['target' => self::TARGET, 'since' => '2020-01-04T12:00:00+00:00']));
        self::assertSame(['https://c.example/3', 'https://b.example/2'], array_slice($sources(['target' => self::TARGET, 'sort-by' => 'rsvp']), 0, 2));
        self::assertSame(['https://a.example/1', 'https://b.example/2'], $sources(['target' => self::TARGET, 'sort-dir' => 'up', 'perPage' => '2', 'page' => '-3']));
    }

    public function testTokenAndDomain(): void
    {
        $all = self::json($this->request('GET', '/api/mentions.jf2', ['token' => $this->token]));
        self::assertCount(6, $all['children']);

        $domain = self::json($this->request('GET', '/api/mentions.jf2', ['token' => $this->token, 'domain' => 'example.com']));
        self::assertCount(6, $domain['children']);

        $unknown = self::json($this->request('GET', '/api/mentions.jf2', ['token' => $this->token, 'domain' => 'nope.example']));
        self::assertSame([], $unknown['children']);

        self::assertSame(401, $this->request('GET', '/api/mentions.jf2', ['token' => 'wrong'])->status);
        self::assertSame(400, $this->request('GET', '/api/mentions.jf2')->status);
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
