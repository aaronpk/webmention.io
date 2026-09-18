<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Model\Account;
use Webmention\Model\Site;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\SiteRepository;
use Webmention\Storage\WebhookDeliveryRepository;
use Webmention\Tests\Support\IntegrationTestCase;
use Webmention\Webmention\WebhookRetries;
use Webmention\Webmention\WebHooks;

/**
 * Failed web hook deliveries are tried again by the workers, with backoff.
 */
final class WebhookRetryTest extends IntegrationTestCase
{
    private const HOOK = 'https://hooks.example.net/webmention';

    private Account $alice;
    private Site $site;
    private WebHooks $hooks;
    private WebhookRetries $retries;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice   = $this->createAccount('alice.example');
        $this->site    = $this->createSite($this->alice, 'alice.example', ['callback_url' => self::HOOK, 'callback_secret' => 's3cret']);
        $this->hooks   = $this->service(WebHooks::class);
        $this->retries = $this->service(WebhookRetries::class);
    }

    private function link(string $source = 'https://bob.example/reply'): int
    {
        return $this->createLink($this->site, 'https://alice.example/post', $source);
    }

    private function notify(int $id): void
    {
        $link = $this->service(LinkRepository::class)->find($id);
        self::assertNotNull($link);
        $this->hooks->notify($this->site, $link, (string) $link->href, (string) $link->targetHref, false);
    }

    public function testAServerErrorSchedulesARetryAndARefusalDoesNot(): void
    {
        $id = $this->link();

        $this->http->respond('POST', self::HOOK, 503, 'down');
        $before = time();
        $this->notify($id);
        self::assertSame(1, $this->retries->count());
        $due = $this->retries->forSite($this->site->id);
        self::assertCount(1, $due);
        $delivery = $this->service(WebhookDeliveryRepository::class)->latestForSite($this->site->id);
        self::assertSame([$delivery?->id], array_keys($due));
        self::assertEqualsWithDelta($before + 60, reset($due), 2, 'the first retry is a minute out');
        self::assertSame(1, $delivery?->attempt);

        // Not due yet: nothing goes out.
        self::assertSame(0, $this->hooks->retryDue(time() + 30));
        self::assertCount(1, $this->http->posts(self::HOOK));

        foreach ([404, 400, 410] as $code) {
            $this->retries->forgetSite($this->site->id);
            $this->http->respond('POST', self::HOOK, $code, 'no');
            $this->notify($id);
            self::assertSame(0, $this->retries->count(), "$code is final");
        }
        foreach ([500, 502, 408, 429] as $code) {
            $this->retries->forgetSite($this->site->id);
            $this->http->respond('POST', self::HOOK, $code, 'later');
            $this->notify($id);
            self::assertSame(1, $this->retries->count(), "$code is retried");
        }

        // A transport error too.
        $this->retries->forgetSite($this->site->id);
        $this->http->respond('POST', self::HOOK, 0, '', [], 'timeout');
        $this->notify($id);
        self::assertSame(1, $this->retries->count());

        // A success schedules nothing.
        $this->retries->forgetSite($this->site->id);
        $this->http->respond('POST', self::HOOK, 200, 'ok');
        $this->notify($id);
        self::assertSame(0, $this->retries->count());
    }

    public function testARetrySendsTheSameBodyWithAFreshSignatureAndRecordsTheAttempt(): void
    {
        $id = $this->link();
        $this->http->respond('POST', self::HOOK, 503, 'down');
        $this->notify($id);
        $first = $this->http->posts(self::HOOK)[0];

        // The secret was rotated in between.
        $this->service(SiteRepository::class)->updateWebhook($this->site->id, self::HOOK, 'new-secret', false);
        $this->http->respond('POST', self::HOOK, 200, 'ok');

        self::assertSame(1, $this->hooks->retryDue(time() + 61));
        $posts = $this->http->posts(self::HOOK);
        self::assertCount(2, $posts);
        $expected = ['secret' => 'new-secret'] + json_decode((string) $first['body'], true);
        self::assertSame($expected, json_decode((string) $posts[1]['body'], true), 'the same payload, with the current secret');
        self::assertContains('X-Webmention-Signature: sha256=' . hash_hmac('sha256', (string) $posts[1]['body'], 'new-secret'), $posts[1]['headers']);

        $deliveries = $this->service(WebhookDeliveryRepository::class)->recentForSite($this->site->id);
        self::assertCount(2, $deliveries);
        self::assertSame(2, $deliveries[0]->attempt);
        self::assertSame('mention', $deliveries[0]->kind);
        self::assertTrue($deliveries[0]->succeeded());
        self::assertSame($id, $deliveries[0]->linkId);
        self::assertSame(0, $this->retries->count(), 'success ends the chain');
        self::assertSame(0, $this->hooks->retryDue(time() + 100_000), 'and nothing is sent twice');
    }

    public function testTheChainStopsAfterSixAttemptsWithGrowingDelays(): void
    {
        $id = $this->link();
        $this->http->respond('POST', self::HOOK, 500, 'still down');
        $this->notify($id);

        $now = time();
        foreach ([60, 300, 1800, 7200, 43200] as $n => $delay) {
            $pending = $this->retries->forSite($this->site->id);
            $due     = (int) reset($pending);
            self::assertEqualsWithDelta($now + $delay, $due, 3, "retry $n is {$delay}s after its failure");
            self::assertSame(0, $this->hooks->retryDue($due - 5), 'not before it is due');
            self::assertSame(1, $this->hooks->retryDue($due + 1));
            $now = time();
        }

        self::assertSame(0, $this->retries->count(), 'given up');
        $deliveries = $this->service(WebhookDeliveryRepository::class)->recentForSite($this->site->id);
        self::assertSame([6, 5, 4, 3, 2, 1], array_map(static fn ($d): int => $d->attempt, $deliveries));
        self::assertCount(6, $this->http->posts(self::HOOK));
    }

    public function testADeletionIsRetriedButAHandSentTestIsNot(): void
    {
        $id = $this->link();
        $this->http->respond('POST', self::HOOK, 503, 'down');

        $this->hooks->deleted($this->site, 'https://bob.example/reply', 'https://alice.example/post', false, $id);
        self::assertSame(1, $this->retries->count());
        self::assertSame(1, $this->hooks->retryDue(time() + 61));
        $latest = $this->service(WebhookDeliveryRepository::class)->latestForSite($this->site->id);
        self::assertSame('deleted', $latest?->kind);
        self::assertSame(2, $latest?->attempt);
        self::assertTrue(json_decode((string) $latest?->requestBody, true)['deleted']);
        $this->retries->forgetSite($this->site->id);

        $delivery = $this->service(WebhookDeliveryRepository::class)->latestForSite($this->site->id);
        self::assertNotNull($delivery);
        $this->hooks->resend($this->site, $delivery);
        self::assertSame(0, $this->retries->count(), 'a test send is never retried');
    }

    public function testRetriesAreDroppedWhenTheSiteChangesItsMind(): void
    {
        $sites = $this->service(SiteRepository::class);
        $this->http->respond('POST', self::HOOK, 503, 'down');

        // Callback URL changed: the new endpoint gets only new mentions.
        $this->notify($this->link('https://bob.example/1'));
        $sites->updateWebhook($this->site->id, 'https://elsewhere.example/hook', 's3cret', false);
        self::assertSame(0, $this->hooks->retryDue(time() + 61));
        self::assertSame(0, $this->retries->count());
        self::assertSame([], $this->http->posts('https://elsewhere.example/hook'));
        $sites->updateWebhook($this->site->id, self::HOOK, 's3cret', false);

        // Callback URL cleared.
        $this->notify($this->link('https://bob.example/2'));
        $sites->updateWebhook($this->site->id, null, null, false);
        self::assertSame(0, $this->hooks->retryDue(time() + 61));
        $sites->updateWebhook($this->site->id, self::HOOK, 's3cret', false);

        // Archived.
        $this->notify($this->link('https://bob.example/3'));
        $sites->archive($this->alice->id, [$this->site->id]);
        self::assertSame(0, $this->hooks->retryDue(time() + 61));
        $sites->unarchive($this->alice->id, $this->site->id);

        // Gone.
        $this->notify($this->link('https://bob.example/4'));
        $this->db->run('DELETE FROM sites WHERE id = ?', [$this->site->id]);
        self::assertSame(0, $this->hooks->retryDue(time() + 61));

        self::assertCount(4, $this->http->posts(self::HOOK), 'only the first attempts went out');
        self::assertSame(0, $this->retries->count());
    }

    public function testTwoWorkersNeverSendTheSameRetry(): void
    {
        $this->http->respond('POST', self::HOOK, 503, 'down');
        $this->notify($this->link());
        $this->http->respond('POST', self::HOOK, 200, 'ok');

        // The second worker sees the member but loses the removal.
        $members = $this->redis->zRange(WebhookRetries::KEY, 0, -1);
        self::assertCount(1, $members);
        $due = $this->retries->due(time() + 61, 20);
        self::assertCount(1, $due);
        self::assertSame([], $this->retries->due(time() + 61, 20));
        self::assertSame(0, $this->hooks->retryDue(time() + 61));
        self::assertCount(1, $this->http->posts(self::HOOK));
    }

    public function testTheSitePageShowsAttemptsAndTheNextRetry(): void
    {
        $id = $this->link();
        $this->http->respond('POST', self::HOOK, 503, 'down');
        $this->notify($id);
        $this->hooks->retryDue(time() + 61);
        $this->signIn($this->alice);

        $page = $this->request('GET', "/settings/sites/{$this->site->id}")->body;
        self::assertStringContainsString('It will be tried again at', $page);
        self::assertStringContainsString('(this was attempt 2 of 6)', $page);
        self::assertStringContainsString('<span class="muted small">attempt 2</span>', $page);
        self::assertStringContainsString('retry at ' . gmdate('M j, Y'), $page);
        self::assertStringContainsString('is tried again after 1 minute, 5 minutes, 30 minutes, 2 hours and 12 hours', $page);

        // A refusal says why it stops.
        $this->retries->forgetSite($this->site->id);
        $this->http->respond('POST', self::HOOK, 410, 'gone');
        $this->notify($id);
        $page = $this->request('GET', "/settings/sites/{$this->site->id}")->body;
        self::assertStringContainsString('since the endpoint answered and refused it', $page);

        // The API docs describe it.
        self::assertStringContainsString('id="webhooks-retries"', $this->request('GET', '/api')->body);
    }

    public function testTheWorkerTurnSendsDueRetries(): void
    {
        // What bin/worker does each turn, without the loop.
        $this->http->respond('POST', self::HOOK, 503, 'down');
        $this->notify($this->link());
        $this->http->respond('POST', self::HOOK, 200, 'ok');
        $this->redis->zAdd(WebhookRetries::KEY, time() - 1, $this->redis->zRange(WebhookRetries::KEY, 0, 0)[0]);

        self::assertSame(1, $this->hooks->retryDue());
        self::assertCount(2, $this->http->posts(self::HOOK));
    }
}
