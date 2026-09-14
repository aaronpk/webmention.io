<?php

declare(strict_types=1);

namespace Webmention\Tests\Unit\Format;

use PHPUnit\Framework\TestCase;
use Webmention\Format\Jf2Format;
use Webmention\Tests\Support\Links;

/** Port of test/helpers/jf2_spec.rb, plus the cases the old app crashed on. */
final class Jf2FormatTest extends TestCase
{
    public function testIncludesSyndicationLinksIfPresent(): void
    {
        $jf2 = Jf2Format::entry(Links::make(['syndication' => '["1","2"]']));

        self::assertSame('entry', $jf2['type']);
        self::assertSame(['1', '2'], $jf2['syndication']);
    }

    public function testOmitsSyndicationWhenAbsent(): void
    {
        self::assertArrayNotHasKey('syndication', Jf2Format::entry(Links::make()));
    }

    public function testIncludesSummaryIfPresent(): void
    {
        $jf2 = Jf2Format::entry(Links::make(['summary' => 'Hello World']));

        self::assertSame(['content-type' => 'text/plain', 'value' => 'Hello World'], $jf2['summary']);
    }

    public function testLegacySitesGetContentTypeAndValue(): void
    {
        $jf2 = Jf2Format::entry(Links::make([
            'content'         => 'Hello <b>World</b>',
            'content_text'    => 'Hello World',
            'site_created_at' => '2015-01-01 00:00:00',
        ]));

        self::assertSame([
            'content-type' => 'text/html',
            'value'        => 'Hello <b>World</b>',
            'html'         => 'Hello <b>World</b>',
            'text'         => 'Hello World',
        ], $jf2['content']);
    }

    public function testNewerSitesGetOnlyHtmlAndText(): void
    {
        $jf2 = Jf2Format::entry(Links::make([
            'content'         => 'Hello <b>World</b>',
            'content_text'    => 'Hello World',
            'site_created_at' => '2018-02-26 17:00:01',
        ]));

        self::assertSame(['html' => 'Hello <b>World</b>', 'text' => 'Hello World'], $jf2['content']);
    }

    public function testTextOnlyContent(): void
    {
        $legacy = Jf2Format::entry(Links::make(['content_text' => 'Hi', 'site_created_at' => '2015-01-01 00:00:00']));
        $modern = Jf2Format::entry(Links::make(['content_text' => 'Hi']));

        self::assertSame(['content-type' => 'text/plain', 'value' => 'Hi', 'text' => 'Hi'], $legacy['content']);
        self::assertSame(['text' => 'Hi'], $modern['content']);
    }

    public function testSetsLikeOfProperty(): void
    {
        $jf2 = Jf2Format::entry(Links::make(['type' => 'like']));

        self::assertSame('http://target.example.com/', $jf2['like-of']);
        self::assertSame('like-of', $jf2['wm-property']);
    }

    public function testRsvpSetsInReplyToAndValue(): void
    {
        $jf2 = Jf2Format::entry(Links::make(['type' => 'rsvp-maybe']));

        self::assertSame('maybe', $jf2['rsvp']);
        self::assertSame('http://target.example.com/', $jf2['in-reply-to']);
        self::assertSame('rsvp', $jf2['wm-property']);
    }

    public function testUnknownAndMalformedTypesAreMentions(): void
    {
        foreach ([null, 'link', 'invite', 'post', 'rsvp', 'rsvp-marty mcguire'] as $type) {
            $jf2 = Jf2Format::entry(Links::make(['type' => $type]));

            self::assertSame('mention-of', $jf2['wm-property'], var_export($type, true));
            self::assertSame('http://target.example.com/', $jf2['mention-of']);
            self::assertArrayNotHasKey('rsvp', $jf2);
        }
    }

    public function testIncludesTimezoneOffsetIfTimezoneIsPresent(): void
    {
        $jf2 = Jf2Format::entry(Links::make(['published' => '2016-02-19 09:16:07', 'published_offset' => -28800]));

        self::assertSame('2016-02-19T01:16:07-08:00', $jf2['published']);
    }

    public function testZeroOffsetIsShown(): void
    {
        $jf2 = Jf2Format::entry(Links::make(['published' => '2016-02-19 09:16:07', 'published_offset' => 0]));

        self::assertSame('2016-02-19T09:16:07+00:00', $jf2['published']);
    }

    public function testOmitsTimezoneOffsetIfTimezoneIsMissing(): void
    {
        $jf2 = Jf2Format::entry(Links::make(['published' => '2016-02-19 01:16:07', 'published_offset' => null]));

        self::assertSame('2016-02-19T01:16:07', $jf2['published']);
    }

    public function testReceivedDateAndIds(): void
    {
        $jf2 = Jf2Format::entry(Links::make(['id' => 42, 'created_at' => '2013-04-25 17:09:33']));

        self::assertSame('2013-04-25T17:09:33Z', $jf2['wm-received']);
        self::assertSame(42, $jf2['wm-id']);
        self::assertSame('http://source.example.org/post', $jf2['wm-source']);
        self::assertSame('http://target.example.com/', $jf2['wm-target']);
        self::assertSame('webmention', $jf2['wm-protocol']);
        self::assertFalse($jf2['wm-private']);
    }

    public function testRewritesArchivedAvatarsToTheCdn(): void
    {
        $jf2 = Jf2Format::entry(Links::make(['author_photo' => 'https://webmention.io/avatar/example.com/abc.png']));

        self::assertSame('https://avatars.webmention.io/example.com/abc.png', $jf2['author']['photo']);
    }

    public function testNullAuthorPhotoDoesNotCrash(): void
    {
        $jf2 = Jf2Format::entry(Links::make(['author_photo' => null]));

        self::assertNull($jf2['author']['photo']);
    }

    public function testKeyOrderMatchesTheOldOutput(): void
    {
        $jf2 = Jf2Format::entry(Links::make([
            'name'         => 'A post',
            'content_text' => 'Hi',
            'type'         => 'reply',
            'relcanonical' => 'http://source.example.org/canonical',
        ]));

        // published_ts was added after published in 2026 (issue 193); the rest is the old order.
        self::assertSame([
            'type', 'author', 'url', 'published', 'published_ts', 'wm-received', 'wm-id', 'wm-source', 'wm-target',
            'wm-protocol', 'name', 'content', 'in-reply-to', 'wm-property', 'wm-private', 'rels',
        ], array_keys($jf2));
    }
}
