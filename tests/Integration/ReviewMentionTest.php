<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Model\Account;
use Webmention\Model\Site;
use Webmention\Storage\LinkRepository;
use Webmention\Tests\Support\IntegrationTestCase;

/**
 * A review of one of your pages (issue 176).
 *
 * XRay reports these as type "review". What matters here is that the review's
 * own relation properties survive, so a review posted in reply to a page is
 * stored as a reply rather than a plain mention, and a review that only
 * likes the page is accepted at all.
 */
final class ReviewMentionTest extends IntegrationTestCase
{
    private const PRODUCT = 'http://target.example.com/product';

    private Account $account;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::parserKeepsReviewRelations()) {
            self::markTestSkipped('Needs p3k/xray v2.0.2, which keeps in-reply-to and like-of on an h-review.');
        }

        $this->account = $this->createAccount('target.example.com');
        $this->site    = $this->createSite($this->account, 'target.example.com');
        $this->http->respond('GET', self::PRODUCT, 200, '<div class="h-entry"><h1 class="p-name">The Product</h1></div>', ['Content-Type' => 'text/html']);
    }

    /** Whether the installed XRay keeps a review's relation properties (v2.0.2 and up). */
    private static function parserKeepsReviewRelations(): bool
    {
        $parsed = \Mf2\parse('<div class="h-review"><a class="u-in-reply-to" href="http://t.example/p">t</a></div>', 'http://s.example/');
        $out    = \p3k\XRay\Formats\Mf2::parse(['body' => $parsed, 'url' => 'http://s.example/', 'code' => 200], new \p3k\HTTP());

        return isset($out['data']['in-reply-to']);
    }

    public function testAReviewInReplyToYourPageIsStoredAsAReply(): void
    {
        $source = 'http://source.example.org/review-reply';
        $this->http->respond('GET', $source, 200, '<div class="h-review">
            <h2 class="p-name">My review</h2>
            <p>In reply to <a class="u-in-reply-to" href="' . self::PRODUCT . '">the product page</a>.</p>
            <span class="p-item h-product"><a class="u-url" href="' . self::PRODUCT . '">The Product</a></span>
            <span class="p-rating">5</span>
            <div class="e-content">Worth every penny</div>
            <a class="u-author h-card" href="http://source.example.org/">Reviewer</a>
        </div>', ['Content-Type' => 'text/html']);

        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => $source, 'target' => self::PRODUCT, 'debug' => '1']);
        self::assertSame(200, $response->status, $response->body);
        self::assertSame('success', self::json($response)['status'], $response->body);

        $link = $this->service(LinkRepository::class)->recentForAccount($this->account->id, 1)[0];
        self::assertSame('reply', $link->type, 'a review posted in reply is a reply, not a bare mention');

        $jf2 = self::json($this->request('GET', '/api/mentions.jf2', ['target' => self::PRODUCT]));
        self::assertCount(1, $jf2['children']);
        self::assertSame('in-reply-to', $jf2['children'][0]['wm-property']);
        self::assertSame(self::PRODUCT, $jf2['children'][0]['in-reply-to']);
        self::assertSame('Worth every penny', $jf2['children'][0]['content']['text']);
        self::assertSame('Reviewer', $jf2['children'][0]['author']['name']);
    }

    public function testAReviewThatOnlyLikesYourPageIsAccepted(): void
    {
        $source = 'http://source.example.org/review-like';
        $this->http->respond('GET', $source, 200, '<div class="h-review">
            <h2 class="p-name">My review</h2>
            <p>I <a class="u-like-of" href="' . self::PRODUCT . '">liked</a> this.</p>
            <span class="p-rating">4</span>
            <div class="e-content">Pretty good</div>
        </div>', ['Content-Type' => 'text/html']);

        // Before the parser kept like-of on a review, the target was nowhere
        // in the parsed tree and the webmention was refused as no_link_found.
        $response = $this->request('POST', '/target.example.com/webmention', post: ['source' => $source, 'target' => self::PRODUCT, 'debug' => '1']);
        self::assertSame(200, $response->status, $response->body);
        self::assertSame('success', self::json($response)['status'], $response->body);

        $link = $this->service(LinkRepository::class)->recentForAccount($this->account->id, 1)[0];
        self::assertSame('like', $link->type);
        self::assertSame('like-of', self::json($this->request('GET', '/api/mentions.jf2', ['target' => self::PRODUCT]))['children'][0]['wm-property']);
    }
}
