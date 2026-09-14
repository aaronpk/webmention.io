<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Controllers\ApiController;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\Database;
use Webmention\Tests\Support\IntegrationTestCase;

/**
 * /api/export.jf2: everything on an account, streamed (issue 109).
 */
final class ExportTest extends IntegrationTestCase
{
    public function testStreamsEveryPublishedMentionOldestFirst(): void
    {
        $account = $this->createAccount('example.com');
        $site    = $this->createSite($account, 'example.com');
        $other   = $this->createSite($account, 'blog.example.com');
        $token   = $this->service(AccountRepository::class)->regenerateToken($account->id);

        // Enough rows to span several batches, inserted directly.
        $total = ApiController::EXPORT_BATCH * 2 + 500;
        $page  = $this->db->insert('pages', ['account_id' => $account->id, 'site_id' => $site->id, 'href' => 'https://example.com/post', 'created_at' => Database::now()]);
        $this->db->pdo()->beginTransaction();
        for ($i = 1; $i <= $total; $i++) {
            $this->db->insert('links', [
                'page_id' => $page, 'site_id' => $site->id, 'account_id' => $account->id,
                'href' => "https://source.example/$i", 'domain' => 'source.example',
                'verified' => 1, 'deleted' => 0, 'type' => 'like', 'protocol' => 'webmention',
                'created_at' => Database::now(), 'updated_at' => Database::now(),
            ]);
        }
        $this->db->pdo()->commit();

        $private = $this->createLink($site, 'https://example.com/post', 'https://private.example/1', ['is_private' => 1]);
        $held    = $this->createLink($site, 'https://example.com/post', 'https://held.example/1', ['verified' => 0, 'status' => 'pending']);
        $gone    = $this->createLink($site, 'https://example.com/post', 'https://gone.example/1', ['deleted' => 1]);
        $onBlog  = $this->createLink($other, 'https://blog.example.com/p', 'https://elsewhere.example/1');

        $response = $this->request('GET', '/api/export.jf2', ['token' => $token]);
        self::assertSame(200, $response->status);
        self::assertTrue($response->isStreamed());
        self::assertStringStartsWith('attachment; filename="webmentions-example.com-', (string) $response->header('content-disposition'));
        self::assertSame('no-store', $response->header('cache-control'));
        self::assertSame('nosniff', $response->header('x-content-type-options'));

        $body = $response->capture();
        $feed = json_decode($body, true);
        self::assertIsArray($feed, 'the streamed body is valid JSON');
        self::assertSame($total + 2 + 2, substr_count($body, "\n"), 'a newline after the opening line and between records, and two around the closing bracket');
        self::assertSame('feed', $feed['type']);
        $ids = array_column($feed['children'], 'wm-id');
        self::assertCount($total + 2, $ids, 'all likes, the private one and the other site; not held or deleted');
        self::assertSame($ids, (static function (array $a): array { sort($a); return $a; })($ids), 'oldest first');
        self::assertContains($private, $ids);
        self::assertContains($onBlog, $ids);
        self::assertNotContains($held, $ids);
        self::assertNotContains($gone, $ids);
        self::assertTrue($feed['children'][array_search($private, $ids, true)]['wm-private']);

        // One export per account every few minutes.
        $again = $this->request('GET', '/api/export', ['token' => $token]);
        self::assertSame(429, $again->status);
        self::assertSame('300', $again->header('retry-after'));
    }

    public function testDomainNarrowsAndAuthIsRequired(): void
    {
        $account = $this->createAccount('example.com');
        $site    = $this->createSite($account, 'example.com');
        $other   = $this->createSite($account, 'blog.example.com');
        $token   = $this->service(AccountRepository::class)->regenerateToken($account->id);
        $this->createLink($site, 'https://example.com/post', 'https://a.example/1');
        $onBlog = $this->createLink($other, 'https://blog.example.com/p', 'https://b.example/1');

        self::assertSame(401, $this->request('GET', '/api/export.jf2')->status);
        self::assertSame(401, $this->request('GET', '/api/export.jf2', ['token' => 'wrong'])->status);
        self::assertSame(404, $this->request('GET', '/api/export.jf2', ['token' => $token, 'domain' => 'nope.example'])->status);

        $response = $this->request('GET', '/api/export.jf2', headers: ['authorization' => "Bearer $token"], query: ['domain' => 'blog.example.com']);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('webmentions-blog.example.com-', (string) $response->header('content-disposition'));
        self::assertSame([$onBlog], array_column(json_decode($response->capture(), true)['children'], 'wm-id'));
    }
}
