<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Redis;
use Webmention\Container;
use Webmention\Controllers\StatsController;
use Webmention\Tests\Support\IntegrationTestCase;
use Webmention\View\Template;
use Webmention\Webmention\Job;
use Webmention\Webmention\Queue;
use Webmention\Webmention\Stats;
use Webmention\Webmention\WebhookRetries;
use Webmention\Webmention\WorkerHeartbeat;

final class StatsTest extends IntegrationTestCase
{
    private const KEY = 'munin-test-key';

    private function withKey(?string $key): void
    {
        $this->container->set(StatsController::class, static fn (Container $c): StatsController => new StatsController(
            $c->get(Template::class),
            $key,
            $c->get(Stats::class),
            $c->get(Queue::class),
            $c->get(WebhookRetries::class),
            $c->get(WorkerHeartbeat::class),
            $c->get(Redis::class),
        ));
    }

    private function data(): string
    {
        $response = $this->request('GET', '/stats/data', headers: ['authorization' => 'Bearer ' . self::KEY]);
        self::assertSame(200, $response->status);

        return $response->body;
    }

    public function testOffWithoutAKey(): void
    {
        $this->withKey(null);

        self::assertSame(404, $this->request('GET', '/stats/data', headers: ['authorization' => 'Bearer x'])->status);
        self::assertSame(404, $this->request('GET', '/stats/config')->status);
    }

    public function testNeedsTheKey(): void
    {
        $this->withKey(self::KEY);

        self::assertSame(401, $this->request('GET', '/stats/data')->status);
        self::assertSame(401, $this->request('GET', '/stats/data', headers: ['authorization' => 'Bearer wrong'])->status);
        self::assertSame(401, $this->request('GET', '/stats/config', headers: ['authorization' => self::KEY])->status);
    }

    public function testConfigListsEveryGraph(): void
    {
        $this->withKey(self::KEY);

        $response = $this->request('GET', '/stats/config', headers: ['authorization' => 'Bearer ' . self::KEY]);

        self::assertSame(200, $response->status);
        foreach (['webmention_received', 'webmention_processed', 'webmention_backlog', 'webmention_workers'] as $graph) {
            self::assertStringContainsString("multigraph $graph\n", $response->body);
        }
        self::assertStringContainsString("no_link_found.type DERIVE\n", $response->body);
        self::assertStringContainsString("queue.type GAUGE\n", $response->body);
    }

    public function testCountsReceivedWebmentions(): void
    {
        $this->withKey(self::KEY);
        $account = $this->createAccount('target.example.com');
        $this->createSite($account, 'target.example.com');

        $post = ['source' => 'http://source.example.org/like-of', 'target' => 'http://target.example.com/entry'];
        $this->request('POST', '/target.example.com/webmention', post: $post);
        $this->request('POST', '/target.example.com/webmention', post: $post);
        $this->request('POST', '/target.example.com/webmention', post: ['source' => 'x']);
        $this->request('POST', '/nobody.example/webmention', post: $post);

        $data = $this->data();
        self::assertStringContainsString("multigraph webmention_received\naccepted.value 1\ninvalid_request.value 1\ninvalid_target.value 1\nrate_limited.value 1\nqueue_full.value 0\n", $data);
        self::assertStringContainsString("multigraph webmention_backlog\nqueue.value 1\n", $data);
    }

    public function testUnknownResultsCountAsOther(): void
    {
        $this->withKey(self::KEY);
        $stats = $this->service(Stats::class);

        $stats->processed('success');
        $stats->processed('success');
        $stats->processed('something_a_token_endpoint_made_up');

        $data = $this->data();
        self::assertStringContainsString("success.value 2\n", $data);
        self::assertStringContainsString("\nother.value 1\n", $data);
        self::assertSame(['success', 'other'], array_keys($this->redis->hGetAll(Stats::KEY_PROCESSED)));
    }

    public function testWorkers(): void
    {
        $this->withKey(self::KEY);
        $this->service(WorkerHeartbeat::class)->beat('1', 0);
        $this->redis->hSet(WorkerHeartbeat::KEY, '2', (string) json_encode(['at' => time() - 3600, 'pid' => 1, 'jobs' => 0]));
        $this->service(Queue::class)->push(new Job(accountId: 1, source: 'http://a.example/', target: 'http://b.example/', token: 't'));

        self::assertStringContainsString("multigraph webmention_workers\nalive.value 1\nstopped.value 1\n", $this->data());
    }
}
