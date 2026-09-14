<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Model\Account;
use Webmention\Model\Site;
use Webmention\Storage\LinkRepository;
use Webmention\Tests\Support\IntegrationTestCase;
use Webmention\Webmention\Processor;
use Webmention\Webmention\Queue;

final class WebmentionFlowTest extends IntegrationTestCase
{
    private const SOURCE = 'http://source.example.org/like-of';
    private const TARGET = 'http://target.example.com/entry';
    private const HOOK   = 'https://hooks.example.net/webmention';

    private Account $account;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = $this->createAccount('target.example.com');
        $this->site    = $this->createSite($this->account, 'target.example.com', [
            'callback_url'    => self::HOOK,
            'callback_secret' => 's3cret',
        ]);
    }

    public function testMissingParameters(): void
    {
        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => self::SOURCE]);

        self::assertSame(400, $response->status);
        self::assertSame(['error' => 'invalid_request', 'error_description' => 'source or target were missing'], self::json($response));
        self::assertSame('*', $response->header('access-control-allow-origin'));
    }

    public function testInvalidUrls(): void
    {
        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => 'ftp://source.example.org/x', 'target' => self::TARGET]);

        self::assertSame(400, $response->status);
        self::assertSame('invalid protocol', self::json($response)['error_details']);
    }

    public function testUnknownAccount(): void
    {
        $response = $this->request('POST', '/nobody.example/webmention', post: ['source' => self::SOURCE, 'target' => self::TARGET]);

        self::assertSame(404, $response->status);
        self::assertSame(['error' => 'not_found', 'error_description' => 'account nobody.example not found'], self::json($response));
    }

    public function testTargetDomainNotOnAccount(): void
    {
        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => self::SOURCE, 'target' => 'http://elsewhere.example/post']);

        self::assertSame(404, $response->status);
        self::assertSame('invalid_target', self::json($response)['error']);
    }

    public function testQueuesProcessesAndNotifies(): void
    {
        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => self::SOURCE, 'target' => self::TARGET]);

        self::assertSame(201, $response->status);
        $body = self::json($response);
        self::assertSame('queued', $body['status']);
        self::assertSame($body['location'], $response->header('location'));
        self::assertMatchesRegularExpression('#^https://webmention\.io/target\.example\.com/webmention/[A-Za-z0-9_-]{20}$#', $body['location']);

        $statusPath = (string) parse_url($body['location'], PHP_URL_PATH);
        $pending    = $this->request('GET', $statusPath);
        self::assertSame('{"status":"pending","source":"http://source.example.org/like-of","target":"http://target.example.com/entry","private":false,"summary":"The webmention is currently being processed","data":{}}', $pending->body);

        $job = $this->service(Queue::class)->pop(1);
        self::assertNotNull($job);
        self::assertSame('success', $this->service(Processor::class)->process($job));

        $status = self::json($this->request('GET', $statusPath));
        self::assertSame('success', $status['status']);
        self::assertSame('like-of', $status['data']['wm-property']);
        self::assertSame('Source Author', $status['data']['author']['name']);

        $links = $this->service(LinkRepository::class)->recentForAccount($this->account->id, 10);
        self::assertCount(1, $links);
        self::assertSame('like', $links[0]->type);
        self::assertSame($job->token, $links[0]->token);

        $hooks = $this->http->posts(self::HOOK);
        self::assertCount(1, $hooks);
        $payload = json_decode((string) $hooks[0]['body'], true);
        self::assertSame(['secret', 'source', 'target', 'private', 'post'], array_keys($payload));
        self::assertSame('s3cret', $payload['secret']);
        self::assertSame(self::TARGET, $payload['post']['like-of']);
        self::assertContains('X-Webmention-Signature: sha256=' . hash_hmac('sha256', (string) $hooks[0]['body'], 's3cret'), $hooks[0]['headers']);
    }

    public function testOverLongUrlsAreRefused(): void
    {
        $long = 'http://source.example.org/' . str_repeat('a', 600);

        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => $long, 'target' => self::TARGET]);

        self::assertSame(400, $response->status);
        self::assertSame('url too long', self::json($response)['error_details']);
        self::assertSame(400, $this->request('POST', '/target.example.com/webmention', post: ['source' => self::SOURCE, 'target' => self::TARGET, 'code' => str_repeat('c', 2000)])->status);
    }

    public function testSynchronousProcessingIsRateLimitedPerClient(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => 'http://source.example.org/nothing', 'target' => self::TARGET . "?n=$i", 'debug' => '1']);
            self::assertSame(400, $response->status, "request $i: {$response->body}");
        }

        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => 'http://source.example.org/nothing', 'target' => self::TARGET . '?n=10', 'debug' => '1']);

        self::assertSame(429, $response->status);
        self::assertSame('rate_limit_exceeded', self::json($response)['error']);
        self::assertSame('60', $response->header('retry-after'));

        // Queued requests are counted separately and still accepted.
        self::assertSame(201, $this->request('POST', '/target.example.com/webmention', post: ['source' => 'http://source.example.org/nothing', 'target' => self::TARGET . '?n=11'])->status);
    }

    public function testAFullQueueAsksSendersToComeBackLater(): void
    {
        $pipe = $this->redis->multi(\Redis::PIPELINE);
        for ($i = 0; $i < 10000; $i++) {
            $pipe->lPush(Queue::KEY, '{}');
        }
        $pipe->exec();

        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => self::SOURCE, 'target' => self::TARGET]);

        self::assertSame(503, $response->status);
        self::assertSame('60', $response->header('retry-after'));
        self::assertSame(10000, $this->service(Queue::class)->length());
    }

    public function testASourceThatRedirectsToABlockedDomainIsRefused(): void
    {
        $this->db->insert('blocks', ['account_id' => $this->account->id, 'domain' => 'spam.example']);
        $this->http->respond('GET', 'http://mirror.example/post', 302, '', ['Location' => 'http://spam.example/post']);
        $this->http->respond('GET', 'http://spam.example/post', 200, (string) file_get_contents(__DIR__ . '/../fixtures/source.example.org/like-of.html'), ['Content-Type' => 'text/html']);

        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => 'http://mirror.example/post', 'target' => self::TARGET, 'debug' => '1']);

        self::assertSame('blocked', self::json($response)['error']);
        self::assertSame('source redirects to a blocked URL', self::json($response)['error_description']);
        self::assertSame([], $this->service(LinkRepository::class)->recentForAccount($this->account->id, 10));
    }

    public function testRateLimitsRepeatedRequests(): void
    {
        $this->request('POST', '/target.example.com/webmention', post: ['source' => self::SOURCE, 'target' => self::TARGET]);
        $second = $this->request('POST', '/target.example.com/webmention', post: ['source' => self::SOURCE, 'target' => self::TARGET]);

        self::assertSame(429, $second->status);
        self::assertSame('rate_limit_exceeded', self::json($second)['error']);
    }

    public function testBrowsersAreRedirectedToTheStatusPage(): void
    {
        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => self::SOURCE, 'target' => self::TARGET], headers: ['accept' => 'text/html']);

        self::assertSame(303, $response->status);
        self::assertStringStartsWith('text/html', (string) $response->header('content-type'));
        self::assertStringContainsString('Queued', $response->body);
    }

    public function testDebugProcessesSynchronously(): void
    {
        $ok = $this->request('POST', '/target.example.com/webmention', post: ['source' => self::SOURCE, 'target' => self::TARGET, 'debug' => '1']);
        self::assertSame(200, $ok->status);
        self::assertSame(['status' => 'success', 'summary' => 'Webmention was successful'], self::json($ok));

        $noLink = $this->request('POST', '/target.example.com/webmention', post: ['source' => 'http://source.example.org/mention', 'target' => 'http://target.example.com/photo', 'debug' => '1']);
        self::assertSame(400, $noLink->status);
        self::assertSame('no_link_found', self::json($noLink)['error']);
    }

    public function testAccountsWhoseUsernameIsNotTheirDomainCanUseEitherName(): void
    {
        $this->db->run('UPDATE accounts SET username = ? WHERE id = ?', ['targetuser', $this->account->id]);

        $sources = [
            '/target.example.com/webmention' => 'http://source.example.org/like-of',
            '/targetuser/webmention'         => 'http://source.example.org/repost-of',
        ];

        foreach ($sources as $path => $source) {
            $response = $this->request('POST', $path, post: ['source' => $source, 'target' => self::TARGET, 'debug' => '1']);
            self::assertSame(200, $response->status, "$path: {$response->body}");
        }
    }

    public function testSiteEndpointChecksTheTargetDomain(): void
    {
        $mismatch = $this->request('POST', '/d/target.example.com/webmention', post: ['source' => self::SOURCE, 'target' => 'http://other.example/x']);
        self::assertSame(400, $mismatch->status);
        self::assertSame('Target domain (other.example) does not match the domain of this webmention endpoint (target.example.com)', self::json($mismatch)['error_description']);

        $ok = $this->request('POST', '/d/target.example.com/webmention', post: ['source' => self::SOURCE, 'target' => self::TARGET, 'debug' => '1']);
        self::assertSame(200, $ok->status);
        self::assertSame('site', $this->service(LinkRepository::class)->recentForAccount($this->account->id, 1)[0]->endpointType);

        self::assertSame(404, $this->request('POST', '/d/unknown.example/webmention', post: ['source' => self::SOURCE, 'target' => self::TARGET])->status);
    }

    public function testRemovedLinkDeletesTheMentionAndNotifies(): void
    {
        $this->request('POST', '/target.example.com/webmention', post: ['source' => self::SOURCE, 'target' => self::TARGET, 'debug' => '1']);

        // The source page changes and no longer links to the target.
        $this->http->respond('GET', self::SOURCE, 200, '<p>Nothing here</p>', ['Content-Type' => 'text/html']);
        $this->redis->flushDb();

        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => self::SOURCE, 'target' => self::TARGET, 'debug' => '1']);

        self::assertSame(400, $response->status);
        self::assertSame([], $this->service(LinkRepository::class)->recentForAccount($this->account->id, 10));

        $hooks = $this->http->posts(self::HOOK);
        self::assertCount(2, $hooks);
        self::assertSame(
            ['secret' => 's3cret', 'source' => self::SOURCE, 'target' => self::TARGET, 'private' => false, 'deleted' => true],
            json_decode((string) $hooks[1]['body'], true),
        );
    }

    public function testFetchErrorsDoNotDeleteExistingMentions(): void
    {
        $this->request('POST', '/target.example.com/webmention', post: ['source' => self::SOURCE, 'target' => self::TARGET, 'debug' => '1']);

        $this->http->respond('GET', self::SOURCE, 0, '', [], 'timeout');
        $this->redis->flushDb();

        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => self::SOURCE, 'target' => self::TARGET, 'debug' => '1']);

        self::assertSame('timeout', self::json($response)['error']);
        self::assertCount(1, $this->service(LinkRepository::class)->recentForAccount($this->account->id, 10));
    }

    public function testBlockedSourcesAreRejected(): void
    {
        $this->db->insert('blocks', ['account_id' => $this->account->id, 'domain' => 'source.example.org']);

        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => self::SOURCE, 'target' => self::TARGET, 'debug' => '1']);

        self::assertSame('blocked', self::json($response)['error']);
        self::assertSame('source domain is blocked', self::json($response)['error_description']);
    }

    /**
     * Issue 221: the Ruby app's three-byte utf8 connection turned every emoji
     * into `????`. Four-byte characters must survive storage and every output.
     */
    public function testNonAsciiTextSurvivesEndToEnd(): void
    {
        $source  = 'http://source.example.org/emoji';
        $author  = 'fluffy 💜';
        $name    = 'Jinx 🐈‍⬛ says 日本語';
        $snippet = 'Café résumé 🚴‍♀️ — 你好, מה שלומך?';

        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => $source, 'target' => self::TARGET, 'debug' => '1']);
        self::assertSame(200, $response->status, $response->body);

        $link = $this->service(LinkRepository::class)->recentForAccount($this->account->id, 1)[0];
        self::assertSame($author, $link->authorName);
        self::assertSame($name, $link->name);
        self::assertStringContainsString($snippet, (string) $link->contentText);
        self::assertStringContainsString('🇳🇿', (string) $link->content);

        $jf2 = self::json($this->request('GET', '/api/mentions.jf2', ['target' => self::TARGET]))['children'][0];
        self::assertSame($author, $jf2['author']['name']);
        self::assertSame($name, $jf2['name']);
        self::assertStringContainsString($snippet, $jf2['content']['text']);
        self::assertStringContainsString('<b>💜</b>', $jf2['content']['html']);

        $json = self::json($this->request('GET', '/api/mentions', ['target' => self::TARGET]))['links'][0];
        self::assertSame($author, $json['data']['author']['name']);
        self::assertSame($name, $json['data']['name']);

        $html = $this->request('GET', '/api/mentions.html', ['target' => self::TARGET])->body;
        self::assertStringContainsString($author, $html);
        self::assertStringContainsString($name, $html);

        $atom = $this->request('GET', '/api/mentions.atom', ['target' => self::TARGET])->body;
        self::assertNotFalse(simplexml_load_string($atom));

        $hook = json_decode((string) $this->http->posts(self::HOOK)[0]['body'], true);
        self::assertSame($author, $hook['post']['author']['name']);
        self::assertSame($name, $hook['post']['name']);
        self::assertStringNotContainsString('??', json_encode($hook, JSON_UNESCAPED_UNICODE));
    }

    public function testInternalErrorsAreNotDescribedToTheSender(): void
    {
        $this->db->pdo()->exec('RENAME TABLE pages TO pages_hidden');
        try {
            $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => self::SOURCE, 'target' => self::TARGET, 'debug' => '1']);
        } finally {
            $this->db->pdo()->exec('RENAME TABLE pages_hidden TO pages');
        }

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('SQLSTATE', $response->body);
        self::assertStringNotContainsString('pages_hidden', $response->body);
    }

    public function testPrivateWebmentionExchangesTheCodeForAToken(): void
    {
        $this->http->respond('HEAD', self::SOURCE, 200, '', ['Link' => '<https://auth.example.org/token>; rel="token_endpoint"']);
        $this->http->respond('POST', 'https://auth.example.org/token', 200, '{"access_token":"tok123","token_type":"Bearer"}', ['Content-Type' => 'application/json']);

        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => self::SOURCE, 'target' => self::TARGET, 'code' => 'abc', 'debug' => '1']);

        self::assertSame(200, $response->status, $response->body);

        $fetch = array_values(array_filter($this->http->requests, static fn (array $r): bool => $r['method'] === 'GET' && $r['url'] === self::SOURCE));
        self::assertContains('Authorization: Bearer tok123', $fetch[0]['headers']);

        $link = $this->service(LinkRepository::class)->recentForAccount($this->account->id, 1)[0];
        self::assertTrue($link->isPrivate);

        $payload = json_decode((string) $this->http->posts(self::HOOK)[0]['body'], true);
        self::assertTrue($payload['private']);
        self::assertTrue($payload['post']['wm-private']);
    }

    public function testSourceThatIsGoneDeletesTheMention(): void
    {
        $this->request('POST', '/target.example.com/webmention', post: ['source' => self::SOURCE, 'target' => self::TARGET, 'debug' => '1']);

        $this->http->respond('GET', self::SOURCE, 410, '', ['Content-Type' => 'text/html']);
        $this->redis->flushDb();

        $this->request('POST', '/target.example.com/webmention', post: ['source' => self::SOURCE, 'target' => self::TARGET, 'debug' => '1']);

        self::assertSame([], $this->service(LinkRepository::class)->recentForAccount($this->account->id, 10));
        self::assertTrue(json_decode((string) $this->http->posts(self::HOOK)[1]['body'], true)['deleted']);
    }

    public function testTokenEndpointThatIsNotHttpIsNeverRequested(): void
    {
        $this->http->respond('HEAD', self::SOURCE, 200, '', ['Link' => '<gopher://127.0.0.1:6379/_SET%20webmention:session:x%20y>; rel="token_endpoint"']);

        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => self::SOURCE, 'target' => self::TARGET, 'code' => 'abc', 'debug' => '1']);

        self::assertSame('invalid_token_endpoint', self::json($response)['error']);
        foreach ($this->http->requests as $request) {
            self::assertStringStartsWith('http', $request['url']);
            self::assertStringNotContainsString('6379', $request['url']);
        }
    }

    public function testStatusPageForBrowsersAndUnknownTokens(): void
    {
        self::assertSame('{"error":"not_found"}', $this->request('GET', '/target.example.com/webmention/nope')->body);
        self::assertSame(404, $this->request('GET', '/target.example.com/webmention/nope')->status);

        $page = $this->request('GET', '/target.example.com/webmention');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('action="/target.example.com/webmention"', $page->body);
    }
}
