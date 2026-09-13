<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Webmention\Model\Site;
use Webmention\Storage\LinkRepository;
use Webmention\Tests\Support\IntegrationTestCase;
use Webmention\Webmention\Processor;
use Webmention\Webmention\SourceFetcher;

/**
 * Port of test/helpers/webmention_processor_spec.rb, run against real XRay parsing of the fixtures.
 * Like the Ruby specs, field extraction is tested without the link-to-target check.
 */
final class ProcessorTest extends IntegrationTestCase
{
    private Processor $processor;
    private SourceFetcher $fetcher;
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processor = $this->service(Processor::class);
        $this->fetcher   = $this->service(SourceFetcher::class);
        $this->site      = $this->createSite($this->createAccount('target.example.com'), 'target.example.com');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function pageTypes(): iterable
    {
        yield 'entry' => ['http://target.example.com/entry', 'entry', 'An Entry'];
        yield 'event' => ['http://target.example.com/event', 'event', 'An Event'];
        yield 'photo' => ['http://target.example.com/photo', 'photo', 'A Photo'];
        yield 'video' => ['http://target.example.com/video', 'video', 'A Video Post'];
        yield 'audio' => ['http://target.example.com/audio', 'audio', 'An Audio Post'];
    }

    #[DataProvider('pageTypes')]
    public function testCreatesPageInSiteAndDetectsItsType(string $target, string $type, string $name): void
    {
        $page = $this->processor->createPageInSite($this->site, $target);

        self::assertSame($type, $page->type);
        self::assertSame($name, $page->name);
        self::assertSame($this->site->id, $page->siteId);
        self::assertSame($target, $page->href);
    }

    public function testResolvesRelativeUrlFromTheSource(): void
    {
        $fields = $this->mf2('http://source.example.org/alternate-url');

        self::assertSame('http://source.example.org/alternate/url', $fields['url']);
    }

    public function testNoUrlIsStoredWhenThePageHasNone(): void
    {
        $fields = $this->mf2('http://source.example.org/no-explicit-url');

        self::assertNull($fields['url'] ?? null);
    }

    public function testPublishedDateIsConvertedToUtc(): void
    {
        $fields = $this->mf2('http://source.example.org/no-explicit-url');

        self::assertSame('2015-11-07 17:00:00', $fields['published']);
        self::assertSame(1446915600, $fields['published_ts']);
    }

    public function testFindsOneSyndicationLink(): void
    {
        self::assertSame('["https://twitter.com/example/status/1"]', $this->mf2('http://source.example.org/one-syndication')['syndication']);
    }

    public function testFindsTwoSyndicationLinks(): void
    {
        self::assertSame(
            '["https://twitter.com/example/status/1","https://facebook.com/1"]',
            $this->mf2('http://source.example.org/two-syndications')['syndication'],
        );
    }

    public function testSetsTimezoneOffsetIfPublishedDateHasTimezone(): void
    {
        self::assertSame(-28800, $this->mf2('http://source.example.org/with-timezone')['published_offset']);
    }

    public function testNullTimezoneOffsetIfPublishedDateHasNoTimezone(): void
    {
        self::assertArrayNotHasKey('published_offset', $this->mf2('http://source.example.org/no-timezone'));
    }

    public function testFindsTheAuthorOfALike(): void
    {
        $fields = $this->author('http://source.example.org/like-of', 'http://target.example.com/entry');

        self::assertSame('Source Author', $fields['author_name']);
        self::assertSame('http://source.example.org/photo.jpg', $fields['author_photo']);
        self::assertSame('http://source.example.org/', $fields['author_url']);
    }

    public function testFindsTheAuthorOfALikeWithNoPhoto(): void
    {
        $fields = $this->author('http://source.example.org/like-of-no-photo', 'http://target.example.com/entry');

        self::assertSame('Source Author', $fields['author_name']);
        self::assertSame('', $fields['author_photo']);
        self::assertSame('http://source.example.org/', $fields['author_url']);
    }

    public function testUsesTheInviteeAsTheAuthorForBridgyInvitesWithNoAuthor(): void
    {
        $fields = $this->author('http://source.example.org/bridgy-invitee-no-author', 'http://target.example.com/');

        self::assertSame('', $fields['author_name']);
        self::assertSame('', $fields['author_photo']);
        self::assertSame('http://target.example.com/', $fields['author_url']);
    }

    /** @return iterable<string, array{string, string, string, bool}> */
    public static function types(): iterable
    {
        yield 'liked a post'                    => ['http://source.example.org/like-of', 'http://target.example.com/entry', 'like', true];
        yield 'was invited to'                  => ['http://source.example.org/bridgy-invitee-no-author', 'http://target.example.com/', 'invite', true];
        yield 'reshared a post'                 => ['http://source.example.org/repost-of', 'http://target.example.com/entry', 'repost', true];
        yield 'bookmarked a post'               => ['http://source.example.org/bookmark-of', 'http://target.example.com/entry', 'bookmark', true];
        yield 'commented on a post'             => ['http://source.example.org/in-reply-to', 'http://target.example.com/entry', 'reply', true];
        yield 'commented on a post linking to'  => ['http://source.example.org/in-reply-to', 'http://another.example.com/entry', 'reply', false];
        yield 'generic mention'                 => ['http://source.example.org/mention', 'http://target.example.com/entry', 'link', true];
    }

    #[DataProvider('types')]
    public function testSetsType(string $source, string $target, string $type, bool $direct): void
    {
        $parsed = $this->fetcher->parse($source);
        self::assertArrayHasKey('data', $parsed, json_encode($parsed));

        $fields = Processor::typeFields($parsed['data'], $target);

        self::assertSame($type, $fields['type']);
        self::assertSame($direct, !isset($fields['is_direct']));
    }

    public function testUnrecognisedRsvpValuesAreNotStoredAsRsvps(): void
    {
        self::assertSame(['type' => 'rsvp-yes'], Processor::typeFields(['rsvp' => 'Yes'], 'http://t.example/'));
        self::assertSame('reply', Processor::typeFields(['rsvp' => 'marty mcguire', 'in-reply-to' => ['http://t.example/']], 'http://t.example/')['type']);
        self::assertSame('link', Processor::typeFields(['rsvp' => 'true'], 'http://t.example/')['type']);
    }

    public function testStoresEmojiInTheLinkName(): void
    {
        $id = $this->createLink($this->site, 'http://target.example.com/entry', 'http://source.example.org/emoji', ['name' => '💩']);

        self::assertSame('💩', $this->service(LinkRepository::class)->find($id)?->name);
    }

    /** @return array<string, mixed> */
    private function mf2(string $source): array
    {
        $parsed = $this->fetcher->parse($source);
        self::assertArrayHasKey('data', $parsed, json_encode($parsed));

        return $this->processor->mf2Fields($parsed['data'], $source);
    }

    /** @return array<string, string> */
    private function author(string $source, string $target): array
    {
        $parsed = $this->fetcher->parse($source);
        self::assertArrayHasKey('data', $parsed, json_encode($parsed));

        return $this->processor->authorFields($parsed['data'], $this->site);
    }
}
