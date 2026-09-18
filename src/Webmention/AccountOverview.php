<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use DateTimeImmutable;
use DateTimeZone;
use Redis;
use Webmention\Format\Jf2Format;
use Webmention\Storage\LinkRepository;

/**
 * What an account received in the last DAYS days by kind, against the DAYS
 * before, for the strip at the top of the dashboard. Two grouped counts on
 * account_index_sort, cached for ten minutes.
 */
final class AccountOverview
{
    public const DAYS = 30;

    private const CACHE_TTL = 600;

    /** Tile order: the browser's type filter => label. */
    public const KINDS = [
        'reply'    => 'Replies',
        'like'     => 'Likes',
        'repost'   => 'Reposts',
        'bookmark' => 'Bookmarks',
        'rsvp'     => 'RSVPs',
        'mention'  => 'Mentions',
    ];

    public function __construct(
        private readonly LinkRepository $links,
        private readonly Redis $redis,
    ) {
    }

    /**
     * @return array{days: int, total: int, before: int, kinds: list<array{type: string, label: string, count: int, before: int}>}
     */
    public function recent(int $accountId): array
    {
        $key    = "webmention:overview:$accountId";
        $cached = $this->redis->get($key);
        if (is_string($cached) && is_array($decoded = json_decode($cached, true))) {
            /** @var array{days: int, total: int, before: int, kinds: list<array{type: string, label: string, count: int, before: int}>} $decoded */
            return $decoded;
        }

        $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $start   = $now->modify('-' . self::DAYS . ' days');
        $earlier = $start->modify('-' . self::DAYS . ' days');
        $format  = 'Y-m-d H:i:s';

        $current = self::fold($this->links->countsByTypeBetween($accountId, $start->format($format), $now->modify('+1 day')->format($format)));
        $before  = self::fold($this->links->countsByTypeBetween($accountId, $earlier->format($format), $start->format($format)));

        $kinds = [];
        foreach (self::KINDS as $type => $label) {
            $kinds[] = ['type' => $type, 'label' => $label, 'count' => $current[$type] ?? 0, 'before' => $before[$type] ?? 0];
        }
        $overview = [
            'days'   => self::DAYS,
            'total'  => array_sum($current),
            'before' => array_sum($before),
            'kinds'  => $kinds,
        ];

        $this->redis->setex($key, self::CACHE_TTL, json_encode($overview) ?: '{}');

        return $overview;
    }

    /** After something changes what is published, so the strip catches up at once. */
    public function forget(int $accountId): void
    {
        $this->redis->del("webmention:overview:$accountId");
    }

    /**
     * Raw link types to the browser's kinds: rsvp-yes and rsvp-no are both
     * RSVPs, and anything not labelled otherwise is a mention.
     *
     * @param array<string, int> $byType
     * @return array<string, int>
     */
    private static function fold(array $byType): array
    {
        $out = [];
        foreach ($byType as $type => $count) {
            $kind = match (Jf2Format::relation($type === '' ? null : $type)) {
                'like-of'     => 'like',
                'repost-of'   => 'repost',
                'in-reply-to' => 'reply',
                'bookmark-of' => 'bookmark',
                'rsvp'        => 'rsvp',
                default       => 'mention',
            };
            $out[$kind] = ($out[$kind] ?? 0) + $count;
        }

        return $out;
    }
}
