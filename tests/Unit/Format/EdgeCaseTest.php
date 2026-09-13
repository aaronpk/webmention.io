<?php

declare(strict_types=1);

namespace Webmention\Tests\Unit\Format;

use PHPUnit\Framework\TestCase;
use Webmention\Controllers\ApiController;
use Webmention\Format\AtomFormat;
use Webmention\Format\JsonFormat;
use Webmention\Format\Jf2Format;
use Webmention\Format\Url;
use Webmention\Http\JsonResponder;
use Webmention\Tests\Support\Links;

/**
 * Real rows contain things the formats must survive: bytes that aren't
 * UTF-8, zero dates, emoji and spaces in URLs.
 */
final class EdgeCaseTest extends TestCase
{
    public function testInvalidUtf8IsSubstitutedRatherThanBreakingTheJson(): void
    {
        $link = Links::make(['author_name' => "Bad \xB1 byte", 'content_text' => "text \xFF"]);

        $json = JsonResponder::encode(Jf2Format::feed([$link]));

        self::assertNotSame('{}', $json);
        self::assertStringContainsString("Bad \u{FFFD} byte", $json);
        self::assertIsArray(json_decode($json, true));
    }

    public function testAtomStaysWellFormedWithInvalidBytesAndControlCharacters(): void
    {
        $xml = AtomFormat::feed([
            Links::make(['href' => "https://example.org/\xB1bad", 'page_href' => "https://example.com/post\x07"]),
        ], 'https://webmention.io');

        self::assertNotFalse(simplexml_load_string($xml));
    }

    public function testZeroPublishedDateIsTreatedAsMissing(): void
    {
        $link = Links::make(['published' => '0000-00-00 00:00:00', 'published_offset' => 0]);

        self::assertNull(Jf2Format::entry($link)['published']);
        self::assertNull(JsonFormat::link($link)['data']['published']);
        self::assertNotNull(ApiController::feedEntry($link)['datetime']);
    }

    public function testEmojiAndSpacesInUrls(): void
    {
        self::assertSame('https://example.com/%F0%9F%8E%89/a%20b', Url::absolutize('a b', 'https://example.com/🎉/x'));
        self::assertSame('example.com', Url::host('https://Example.com/🎉'));
        self::assertTrue(Url::isHttp('https://example.com/🎉'));
        self::assertFalse(Url::isHttp('javascript:alert(1)'));
        self::assertNull(Url::host('not a url'));
    }

    public function testHtmlFeedEntryOnlyEmitsHttpUrls(): void
    {
        $entry = ApiController::feedEntry(Links::make([
            'author_name'  => 'Eve',
            'author_url'   => 'javascript:alert(1)',
            'author_photo' => 'data:image/png;base64,xx',
            'url'          => 'javascript:alert(2)',
        ]));

        self::assertNull($entry['author_url']);
        self::assertNull($entry['author_photo']);
        self::assertNull($entry['url']);
        self::assertSame('Eve', $entry['author_name']);
        self::assertTrue($entry['has_author']);
    }

    public function testSyndicationThatIsNotAListIsIgnored(): void
    {
        self::assertNull(Links::make(['syndication' => '"just a string"'])->syndications());
        self::assertNull(Links::make(['syndication' => 'not json'])->syndications());
    }
}
