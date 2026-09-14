<?php

declare(strict_types=1);

namespace Webmention\Tests\Unit\Format;

use PHPUnit\Framework\TestCase;
use Webmention\Format\AtomFormat;
use Webmention\Format\JsonFormat;
use Webmention\Tests\Support\Links;

final class JsonAndAtomFormatTest extends TestCase
{
    public function testJsonShape(): void
    {
        $json = JsonFormat::link(Links::make([
            'id'               => 7,
            'author_name'      => 'Joe',
            'author_url'       => '/about',
            'author_photo'     => '',
            'name'             => 'Title',
            'content'          => '',
            'content_text'     => 'Text',
            'published_offset' => -28800,
            'type'             => 'rsvp-yes',
            'updated_at'       => '2026-09-04 04:56:48',
        ]));

        self::assertSame([
            'source'        => 'http://source.example.org/post',
            'verified'      => true,
            'verified_date' => '2026-09-04T04:56:48+00:00',
            'id'            => 7,
            'private'       => false,
            'data'          => [
                'author'       => ['name' => 'Joe', 'url' => 'http://source.example.org/about', 'photo' => null],
                'url'          => 'http://source.example.org/post',
                'name'         => 'Title',
                'content'      => 'Text',
                'published'    => '2016-02-19T01:16:07-08:00',
                'published_ts' => 1455873367,
                'rsvp'         => 'yes',
            ],
            'activity'      => ['type' => 'rsvp'],
            'target'        => 'http://target.example.com/',
        ], $json);
    }

    public function testJsonOmitsAuthorWhenThereIsNone(): void
    {
        self::assertArrayNotHasKey('author', JsonFormat::link(Links::make())['data']);
    }

    public function testMalformedRsvpTypeIsReportedAsRsvpActivity(): void
    {
        $json = JsonFormat::link(Links::make(['type' => 'rsvp-marty mcguire']));

        self::assertSame('rsvp', $json['activity']['type']);
        self::assertArrayNotHasKey('rsvp', $json['data']);
    }

    public function testAtomEntriesCarryTheAuthorLinkAndContent(): void
    {
        $xml = AtomFormat::feed([
            Links::make([
                'id'           => 1,
                'author_name'  => 'Joe & Jane',
                'author_url'   => 'https://source.example.org/',
                'url'          => 'https://source.example.org/reply/1',
                'content'      => '<p>Nice <b>post</b> &amp; thanks</p>',
                'content_text' => 'Nice post & thanks',
                'type'         => 'reply',
            ]),
            Links::make(['id' => 2, 'name' => 'Only a title', 'author_name' => '']),
            Links::make(['id' => 3, 'type' => 'like', 'author_url' => 'javascript:alert(1)']),
        ], 'https://webmention.io');

        $doc = simplexml_load_string($xml);
        self::assertNotFalse($doc);
        $doc->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');
        $entries = $doc->xpath('//a:entry');

        self::assertSame('Joe & Jane', (string) $entries[0]->author->name);
        self::assertSame('https://source.example.org/', (string) $entries[0]->author->uri);
        self::assertSame('https://source.example.org/reply/1', (string) $entries[0]->link['href']);
        self::assertSame('html', (string) $entries[0]->content['type']);
        self::assertSame('<p>Nice <b>post</b> &amp; thanks</p>', (string) $entries[0]->content);
        self::assertSame('2016-02-19T09:16:07+00:00', (string) $entries[0]->published);

        // No content: the name as text, and the source host as the author.
        self::assertSame('text', (string) $entries[1]->content['type']);
        self::assertSame('Only a title', (string) $entries[1]->content);
        self::assertSame('source.example.org', (string) $entries[1]->author->name);

        // Nothing at all: the old "X liked Y" xhtml block; an unsafe author URL is left out.
        self::assertSame('xhtml', (string) $entries[2]->content['type']);
        self::assertEmpty($entries[2]->author->uri);
        self::assertStringNotContainsString('javascript:', $xml);
    }

    public function testAtomFeedIsWellFormedAndDescribesEachMention(): void
    {
        $xml = AtomFormat::feed([
            Links::make(['id' => 900, 'href' => 'http://tantek.com/2013/113/b1/thread', 'page_href' => 'http://indiewebcamp.com', 'type' => 'like']),
            Links::make(['id' => 901, 'href' => 'https://example.org/a?b=1&c=2', 'page_href' => 'https://example.com/post', 'type' => 'rsvp-yes']),
        ], 'https://webmention.io');

        $doc = simplexml_load_string($xml);
        self::assertNotFalse($doc);
        $doc->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');

        self::assertSame('https://webmention.io/api/mentions.atom', (string) $doc->id);
        self::assertSame('Mentions', (string) $doc->title);

        $entries = $doc->xpath('//a:entry');
        self::assertCount(2, $entries);
        self::assertSame('tantek.com liked /', (string) $entries[0]->title);
        self::assertSame('https://webmention.io/api/mention/900', (string) $entries[0]->id);
        self::assertSame('http://tantek.com/2013/113/b1/thread liked http://indiewebcamp.com/', (string) $entries[0]->summary);
        self::assertSame('example.org RSVPed “yes” to /post', (string) $entries[1]->title);
        self::assertStringContainsString('href="https://example.org/a?b=1&amp;c=2"', $xml);
    }
}
