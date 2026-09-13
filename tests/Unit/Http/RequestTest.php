<?php

declare(strict_types=1);

namespace Webmention\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Webmention\Controllers\ApiController;
use Webmention\Http\JsonResponder;
use Webmention\Http\Request;

final class RequestTest extends TestCase
{
    public function testInputListAcceptsEveryParameterStyle(): void
    {
        $single  = new Request('GET', '/', query: ['wm-property' => 'in-reply-to']);
        $list    = new Request('GET', '/', query: ['wm-property' => ['in-reply-to', 'rsvp']]);
        $indexed = new Request('GET', '/', query: ['wm-property' => [0 => 'like-of', 1 => 'repost-of']]);
        $empty   = new Request('GET', '/', query: ['wm-property' => '']);

        self::assertSame(['in-reply-to'], $single->inputList('wm-property'));
        self::assertSame(['in-reply-to', 'rsvp'], $list->inputList('wm-property'));
        self::assertSame(['like-of', 'repost-of'], $indexed->inputList('wm-property'));
        self::assertSame([], $empty->inputList('wm-property'));
        self::assertNull($list->input('wm-property'));
    }

    public function testPostTakesPrecedenceOverQuery(): void
    {
        $request = new Request('POST', '/', query: ['source' => 'q'], post: ['source' => 'p']);

        self::assertSame('p', $request->input('source'));
    }

    public function testWmPropertyToTypes(): void
    {
        self::assertSame(
            ['reply', 'like', 'repost', 'bookmark', 'link', 'rsvp-yes', 'rsvp-no', 'rsvp-maybe', 'rsvp-interested'],
            ApiController::typesForProperties(['in-reply-to', 'like-of', 'repost-of', 'bookmark-of', 'mention-of', 'rsvp']),
        );
    }

    public function testSinceIsConvertedToUtc(): void
    {
        self::assertSame('2017-06-01 17:00:00', ApiController::parseSince('2017-06-01T10:00:00-0700'));
        self::assertNull(ApiController::parseSince('not a date'));
    }

    public function testJsonpCallbacksAreRestricted(): void
    {
        self::assertTrue(JsonResponder::validCallback('f'));
        self::assertTrue(JsonResponder::validCallback('jQuery123_456'));
        self::assertTrue(JsonResponder::validCallback('app.render'));
        self::assertFalse(JsonResponder::validCallback('alert(1);f'));
        self::assertFalse(JsonResponder::validCallback('<script>'));
    }
}
