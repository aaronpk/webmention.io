<?php

declare(strict_types=1);

namespace Webmention\Tests\Support;

use Webmention\Model\Link;

/**
 * Builds Link objects for unit tests without touching the database.
 */
final class Links
{
    /** @param array<string, mixed> $overrides Column names, as in a links row. */
    public static function make(array $overrides = []): Link
    {
        return Link::fromRow([...self::defaults(), ...$overrides]);
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'id'               => 1,
            'href'             => 'http://source.example.org/post',
            'domain'           => 'source.example.org',
            'verified'         => 1,
            'protocol'         => 'webmention',
            'endpoint_type'    => 'account',
            'is_private'       => 0,
            'summary'          => null,
            'created_at'       => '2016-02-19 09:20:00',
            'updated_at'       => '2016-02-19 09:21:00',
            'page_id'          => 1,
            'author_url'       => '',
            'author_name'      => '',
            'author_photo'     => '',
            'name'             => null,
            'content'          => null,
            'content_text'     => null,
            'published'        => '2016-02-19 09:16:07',
            'published_ts'     => 1455873367,
            'published_offset' => 0,
            'url'              => null,
            'relcanonical'     => null,
            'type'             => 'link',
            'is_direct'        => 1,
            'site_id'          => 1,
            'account_id'       => 1,
            'syndication'      => null,
            'token'            => 'abc',
            'swarm_coins'      => null,
            'deleted'          => 0,
            'photo'            => null,
            'video'            => null,
            'audio'            => null,
            'page_href'        => 'http://target.example.com/',
            'site_created_at'  => '2019-01-01 00:00:00',
        ];
    }
}
