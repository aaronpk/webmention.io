<?php

declare(strict_types=1);

namespace Webmention\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Webmention\Format\Url;
use Webmention\Tests\Support\Links;

/** Port of test/models/link_spec.rb, plus Url edge cases. */
final class LinkTest extends TestCase
{
    public function testConvertsPublishedDateToLocalTime(): void
    {
        $link = Links::make(['published' => '2015-12-01 09:30:00', 'published_offset' => -28800]);

        self::assertSame('2015-12-01T01:30:00-08:00', $link->publishedDate()?->format('Y-m-d\TH:i:sP'));
    }

    public function testKeepsDateInUtcWhenNoOffsetIsSpecified(): void
    {
        $link = Links::make(['published' => '2015-12-01 09:30:00', 'published_offset' => null]);

        self::assertSame('2015-12-01T09:30:00+00:00', $link->publishedDate()?->format('Y-m-d\TH:i:sP'));
    }

    public function testReturnsTheAbsoluteUrl(): void
    {
        $link = Links::make(['href' => 'https://example.com/foo/', 'url' => '/bar']);

        self::assertSame('https://example.com/bar', $link->absoluteUrl());
    }

    public function testAbsoluteUrlFallsBackToHref(): void
    {
        self::assertSame('http://source.example.org/post', Links::make(['url' => ''])->absoluteUrl());
    }

    public function testHandlesAUrlWithEmojiInIt(): void
    {
        $link = Links::make(['href' => 'http://example.com/💩/', 'url' => 'foo/']);

        self::assertSame('http://example.com/%F0%9F%92%A9/foo/', $link->absoluteUrl());
    }

    public function testSyndications(): void
    {
        self::assertNull(Links::make()->syndications());
        self::assertSame(['https://twitter.com/example/status/1'], Links::make(['syndication' => '["https://twitter.com/example/status/1"]'])->syndications());
        self::assertSame(
            ['https://twitter.com/example/status/1', 'https://facebook.com/1'],
            Links::make(['syndication' => '["https://twitter.com/example/status/1","https://facebook.com/1"]'])->syndications(),
        );
    }

    public function testAbsolutizeEdgeCases(): void
    {
        self::assertSame('http://a.example/x', Url::absolutize(' http://a.example/x ', 'http://b.example/'));
        self::assertSame('http://b.example/post#frag', Url::absolutize('#frag', 'http://b.example/post'));
        self::assertSame('http://b.example/post', Url::absolutize('', 'http://b.example/post'));
        self::assertSame('https://b.example/', Url::absolutize('//B.Example', 'https://x.example/'));
        self::assertSame('http://b.example/a/c', Url::absolutize('c', 'http://b.example/a/b'));
    }
}
