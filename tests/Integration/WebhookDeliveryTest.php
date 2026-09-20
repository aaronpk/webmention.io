<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Model\Account;
use Webmention\Model\Site;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\SiteRepository;
use Webmention\Storage\WebhookDeliveryRepository;
use Webmention\Tests\Support\IntegrationTestCase;
use Webmention\Webmention\WebHooks;

/**
 * Web hook deliveries are recorded and can be re-sent from the site page (issue 231).
 */
final class WebhookDeliveryTest extends IntegrationTestCase
{
    private const HOOK = 'https://hooks.example.net/in';

    private Account $alice;
    private Account $mallory;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice   = $this->createAccount('alice.example');
        $this->mallory = $this->createAccount('mallory.example');
        $this->site    = $this->createSite($this->alice, 'alice.example', ['callback_url' => self::HOOK, 'callback_secret' => 's3cret']);
    }

    public function testEveryAttemptIsRecordedWithWhatWasSentAndWhatCameBack(): void
    {
        $id   = $this->createLink($this->site, 'https://alice.example/post', 'https://bob.example/reply', ['type' => 'reply']);
        $link = $this->service(LinkRepository::class)->find($id);
        $hooks = $this->service(WebHooks::class);
        $log   = $this->service(WebhookDeliveryRepository::class);

        $this->http->respond('POST', self::HOOK, 200, 'thanks');
        $ok = $hooks->notify($this->site, $link, 'https://bob.example/reply', 'https://alice.example/post', false);
        self::assertNotNull($ok);
        self::assertTrue($ok->succeeded());
        self::assertSame('HTTP 200', $ok->result());
        self::assertSame('mention', $ok->kind);
        self::assertSame($id, $ok->linkId);
        self::assertSame(self::HOOK, $ok->url);
        self::assertSame('thanks', $ok->responseBody);
        $payload = json_decode($ok->requestBody, true);
        self::assertSame(['secret', 'source', 'target', 'private', 'post'], array_keys($payload));
        self::assertSame($id, $payload['post']['wm-id']);

        $this->http->respond('POST', self::HOOK, 500, str_repeat('x', 5000));
        $failed = $hooks->deleted($this->site, 'https://bob.example/reply', 'https://alice.example/post', false, $id);
        self::assertFalse($failed->succeeded());
        self::assertSame('HTTP 500', $failed->result());
        self::assertSame('deleted', $failed->kind);
        self::assertSame(WebhookDeliveryRepository::RESPONSE_EXCERPT, strlen((string) $failed->responseBody), 'only an excerpt of the reply is kept');

        $this->http->respond('POST', self::HOOK, 0, '', [], 'Could not resolve host: hooks.example.net');
        $error = $hooks->notify($this->site, $link, 'https://bob.example/reply', 'https://alice.example/post', false);
        self::assertFalse($error->succeeded());
        self::assertSame('Simulated Could not resolve host: hooks.example.net', $error->result(), 'the descriptive text, not the short code');
        self::assertNull($error->statusCode);

        $recent = $log->recentForSite($this->site->id);
        self::assertSame([$error->id, $failed->id, $ok->id], array_map(static fn ($d) => $d->id, $recent), 'newest first');
        self::assertSame($error->id, $log->latestForSite($this->site->id)?->id);

        // A site with no callback URL records nothing.
        $quiet = $this->createSite($this->alice, 'quiet.example');
        self::assertNull($hooks->notify($quiet, $link, 'https://bob.example/reply', 'https://quiet.example/', false));
        self::assertSame([], $log->recentForSite($quiet->id));
    }

    public function testOnlyTheNewestFiftyAreKept(): void
    {
        $id   = $this->createLink($this->site, 'https://alice.example/post', 'https://bob.example/reply');
        $link = $this->service(LinkRepository::class)->find($id);
        $this->http->respond('POST', self::HOOK, 202);

        $hooks = $this->service(WebHooks::class);
        $first = $hooks->notify($this->site, $link, 'https://bob.example/reply', 'https://alice.example/post', false);
        for ($i = 0; $i < WebhookDeliveryRepository::KEEP + 4; $i++) {
            $last = $hooks->notify($this->site, $link, 'https://bob.example/reply', 'https://alice.example/post', false);
        }

        $recent = $this->service(WebhookDeliveryRepository::class)->recentForSite($this->site->id, 100);
        self::assertCount(WebhookDeliveryRepository::KEEP, $recent);
        self::assertSame($last->id, $recent[0]->id);
        self::assertNull($this->service(WebhookDeliveryRepository::class)->findForSite($this->site->id, $first->id));
    }

    public function testTheSitePageShowsDeliveriesAndExplainsAFailure(): void
    {
        $id   = $this->createLink($this->site, 'https://alice.example/post', 'https://bob.example/reply');
        $link = $this->service(LinkRepository::class)->find($id);
        $hooks = $this->service(WebHooks::class);
        $this->signIn($this->alice);

        $page = $this->request('GET', "/settings/sites/{$this->site->id}")->body;
        self::assertStringContainsString('Web hook deliveries', $page);
        self::assertStringContainsString('Nothing has been sent to', $page);
        self::assertStringContainsString('Send the latest webmention now</button>', $page);
        self::assertStringNotContainsString('disabled>Send', $page, 'there is a mention to send');

        $this->http->respond('POST', self::HOOK, 200, '{"ok":true}');
        $hooks->notify($this->site, $link, 'https://bob.example/reply', 'https://alice.example/post', false);
        $page = $this->request('GET', "/settings/sites/{$this->site->id}")->body;
        self::assertStringContainsString('was accepted (HTTP 200)', $page);
        self::assertStringContainsString('<span class="badge">HTTP 200</span>', $page);
        self::assertStringContainsString('&quot;ok&quot;:true', $page, 'the response is shown');
        self::assertStringContainsString('&quot;wm-id&quot;: ' . $id, $page, 'the request is shown, pretty-printed');
        self::assertStringContainsString('name="delivery_id"', $page);

        $this->http->respond('POST', self::HOOK, 503, 'busy');
        $hooks->notify($this->site, $link, 'https://bob.example/reply', 'https://alice.example/post', false);
        $page = $this->request('GET', "/settings/sites/{$this->site->id}")->body;
        self::assertStringContainsString('failed: HTTP 503', $page);
        self::assertStringContainsString('class="alert"', $page);
        self::assertStringContainsString('badge badge-error">HTTP 503', $page);

        // No callback URL: no card at all.
        $this->service(SiteRepository::class)->updateWebhook($this->site->id, '', '', true, 'off');
        self::assertStringNotContainsString('Web hook deliveries', $this->request('GET', "/settings/sites/{$this->site->id}")->body);
    }

    public function testResendReplaysTheBodyWithTheCurrentSecret(): void
    {
        $id   = $this->createLink($this->site, 'https://alice.example/post', 'https://bob.example/reply');
        $link = $this->service(LinkRepository::class)->find($id);
        $this->http->respond('POST', self::HOOK, 200);
        $original = $this->service(WebHooks::class)->notify($this->site, $link, 'https://bob.example/reply', 'https://alice.example/post', false);

        // The secret changed since; a re-send uses the new one.
        $this->service(SiteRepository::class)->updateWebhook($this->site->id, self::HOOK, 'n3w', true, 'off');
        $csrf = $this->signIn($this->alice);

        $response = $this->request('POST', '/webhook/resend', post: ['site_id' => (string) $this->site->id, 'delivery_id' => (string) $original->id, 'csrf' => $csrf]);
        self::assertSame(303, $response->status);
        self::assertSame("/settings/sites/{$this->site->id}#deliveries", $response->header('location'));
        self::assertStringContainsString('Sent. The result is the newest delivery below.', $this->request('GET', "/settings/sites/{$this->site->id}")->body);

        $posts = $this->http->posts(self::HOOK);
        self::assertCount(2, $posts);
        $first  = json_decode((string) $posts[0]['body'], true);
        $second = json_decode((string) $posts[1]['body'], true);
        self::assertSame('n3w', $second['secret']);
        unset($first['secret'], $second['secret']);
        self::assertSame($first, $second, 'the payload is the same apart from the secret');
        self::assertContains('X-Webmention-Signature: sha256=' . hash_hmac('sha256', (string) $posts[1]['body'], 'n3w'), $posts[1]['headers']);

        $latest = $this->service(WebhookDeliveryRepository::class)->latestForSite($this->site->id);
        self::assertSame('test', $latest?->kind);
        self::assertSame($id, $latest?->linkId);
        self::assertStringNotContainsString('Sent. The result is the newest delivery below.', $this->request('GET', "/settings/sites/{$this->site->id}", ['sent' => '1'])->body, 'shown once, after the redirect, never from the URL');
    }

    public function testSendingTheLatestMentionAndTheLimits(): void
    {
        $csrf = $this->signIn($this->alice);
        $this->http->respond('POST', self::HOOK, 200);

        // Nothing published yet: nothing to send.
        $response = $this->request('POST', '/webhook/resend', post: ['site_id' => (string) $this->site->id, 'csrf' => $csrf]);
        self::assertSame(303, $response->status);
        self::assertSame("/settings/sites/{$this->site->id}#deliveries", $response->header('location'));
        self::assertCount(0, $this->http->posts(self::HOOK));
        $page = $this->request('GET', "/settings/sites/{$this->site->id}")->body;
        self::assertStringContainsString('This site has no published webmention to send yet.', $page);
        self::assertStringContainsString('disabled>Send the latest webmention now', $page);

        $this->createLink($this->site, 'https://alice.example/post', 'https://old.example/1');
        $newest = $this->createLink($this->site, 'https://alice.example/post', 'https://new.example/2');
        $this->createLink($this->site, 'https://alice.example/post', 'https://held.example/3', ['verified' => 0, 'status' => 'pending']);

        $response = $this->request('POST', '/webhook/resend', post: ['site_id' => (string) $this->site->id, 'csrf' => $csrf]);
        self::assertSame("/settings/sites/{$this->site->id}#deliveries", $response->header('location'));
        $posts = $this->http->posts(self::HOOK);
        self::assertCount(1, $posts);
        self::assertSame($newest, json_decode((string) $posts[0]['body'], true)['post']['wm-id'], 'the newest published one, not the held one');
        self::assertSame('test', $this->service(WebhookDeliveryRepository::class)->latestForSite($this->site->id)?->kind);

        // Ten attempts a minute per account; the empty one above counted too.
        for ($i = 0; $i < 8; $i++) {
            $this->request('POST', '/webhook/resend', post: ['site_id' => (string) $this->site->id, 'csrf' => $csrf]);
        }
        $refused = $this->request('POST', '/webhook/resend', post: ['site_id' => (string) $this->site->id, 'csrf' => $csrf]);
        self::assertSame("/settings/sites/{$this->site->id}#deliveries", $refused->header('location'));
        self::assertStringContainsString('Too many sends in a row', $this->request('GET', "/settings/sites/{$this->site->id}")->body);
        self::assertCount(9, $this->http->posts(self::HOOK));

        // Not someone else's site or delivery, and not a site without a hook.
        $mcsrf = $this->signIn($this->mallory);
        self::assertSame(404, $this->request('POST', '/webhook/resend', post: ['site_id' => (string) $this->site->id, 'csrf' => $mcsrf])->status);
        $own = $this->createSite($this->mallory, 'mallory.example', ['callback_url' => 'https://mallory.example/hook']);
        $alicesDelivery = $this->service(WebhookDeliveryRepository::class)->latestForSite($this->site->id);
        self::assertSame(404, $this->request('POST', '/webhook/resend', post: ['site_id' => (string) $own->id, 'delivery_id' => (string) $alicesDelivery?->id, 'csrf' => $mcsrf])->status);
        $quiet = $this->createSite($this->mallory, 'quiet.example');
        self::assertSame(400, $this->request('POST', '/webhook/resend', post: ['site_id' => (string) $quiet->id, 'csrf' => $mcsrf])->status);
    }
}
