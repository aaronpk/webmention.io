<?php

declare(strict_types=1);

namespace Webmention\Format;

use Webmention\Model\Link;

/**
 * Sample mentions for /api/example/mentions.jf2: one of every shape the real
 * feed produces, so a client can be tested against all of them at once
 * (issue 77). Built as Link rows and rendered by the same Jf2Format as the
 * live API, so the output cannot drift from production.
 *
 * Every URL is on a .example host or one of this site's own placeholder
 * images. Names are made up from word lists, chosen by a seed, so no entry
 * resembles a real person.
 */
final class ExampleMentions
{
    private const ADJECTIVES = ['Quiet', 'Amber', 'Brisk', 'Cobalt', 'Dusty', 'Gentle', 'Hollow', 'Ivory', 'Jolly', 'Keen', 'Lunar', 'Mossy', 'Nimble', 'Olive', 'Plucky', 'Rusty', 'Silver', 'Tidy', 'Velvet', 'Wandering'];
    private const ANIMALS    = ['Heron', 'Otter', 'Badger', 'Finch', 'Marten', 'Lynx', 'Plover', 'Beetle', 'Newt', 'Kestrel', 'Walrus', 'Ibis', 'Gecko', 'Stoat', 'Puffin', 'Tapir', 'Wren', 'Vole', 'Yak', 'Egret'];
    private const EMOJI      = ['💜', '🌱', '🚲', '🐈‍⬛', '☕', '🏳️‍🌈', '📚', '🎨'];

    /** A site created before this gets the legacy content shape; see Jf2Format. */
    private const OLD_SITE = '2015-06-01 00:00:00';
    private const NEW_SITE = '2021-03-14 00:00:00';

    /**
     * @return list<Link>
     */
    public static function links(string $target, int $seed, string $baseUrl): array
    {
        mt_srand($seed);

        $people = [];
        $avatar = static fn (int $n): string => $baseUrl . '/img/example/avatar-' . $n . '.svg';
        $photo  = static fn (int $n): string => $baseUrl . '/img/example/photo-' . $n . '.svg';

        // A fixed cast per response so the same person can appear twice.
        for ($i = 0; $i < 8; $i++) {
            $name     = self::ADJECTIVES[mt_rand(0, count(self::ADJECTIVES) - 1)] . ' ' . self::ANIMALS[mt_rand(0, count(self::ANIMALS) - 1)];
            $slug     = strtolower(str_replace(' ', '', $name));
            $people[] = [
                'name'  => $name,
                'emoji' => $name . ' ' . self::EMOJI[mt_rand(0, count(self::EMOJI) - 1)],
                'url'   => "https://$slug.example/",
                'photo' => $avatar(($i % 4) + 1),
                'post'  => static fn (string $path): string => "https://$slug.example/$path",
            ];
        }

        $p = static fn (int $i): array => $people[$i % count($people)];
        $rows = [];

        // 1. A reply with html and text, a photo, a timezone offset and syndication links.
        $rows[] = self::row(1001, $target, $p(0), '2026-03-02 17:45:10', [
            'href'             => $p(0)['post']('2026/03/02/reply'),
            'url'              => $p(0)['post']('2026/03/02/reply'),
            'type'             => 'reply',
            'content'          => '<p>This is exactly the thing I was looking for. I wrote up how I use it <a href="' . $p(0)['post']('notes/setup') . '">over here</a>.</p>',
            'content_text'     => 'This is exactly the thing I was looking for. I wrote up how I use it over here.',
            'published'        => '2026-03-02 17:30:00',
            'published_ts'     => 1772472600,
            'published_offset' => -25200,
            'syndication'      => json_encode([$p(0)['post']('syndicated/1'), 'https://social.example/@' . strtolower($p(0)['name']) . '/1'], JSON_UNESCAPED_SLASHES),
        ]);

        // 2. A reply relayed by a bridge: the source is on the bridge, the url is the original post, emoji in the name.
        $rows[] = self::row(1002, $target, $p(1), '2026-03-03 09:12:44', [
            'href'         => 'https://bridge.example/comment/social/@' . strtolower(str_replace(' ', '', $p(1)['name'])) . '@social.example/118000000000000001',
            'url'          => 'https://social.example/@' . strtolower(str_replace(' ', '', $p(1)['name'])) . '/118000000000000001',
            'author_name'  => $p(1)['emoji'],
            'type'         => 'reply',
            'content'      => '<p>Bridged from a social network 🌉 with <em>formatting</em> kept.</p>',
            'content_text' => 'Bridged from a social network 🌉 with formatting kept.',
            'published'    => '2026-03-03 09:10:00',
            'published_ts' => 1772529000,
            'published_offset' => 0,
        ]);

        // 3. A like: author only.
        $rows[] = self::row(1003, $target, $p(2), '2026-03-03 12:00:00', [
            'href' => $p(2)['post']('likes/42'),
            'url'  => $p(2)['post']('likes/42'),
            'type' => 'like',
            'published'    => '2026-03-03 11:58:00',
            'published_ts' => 1772539080,
            'published_offset' => 3600,
        ]);

        // 4. A repost.
        $rows[] = self::row(1004, $target, $p(3), '2026-03-03 15:20:05', [
            'href' => $p(3)['post']('reposts/7'),
            'url'  => $p(3)['post']('reposts/7'),
            'type' => 'repost',
            'published'    => '2026-03-03 15:19:00',
            'published_ts' => 1772551140,
            'published_offset' => 0,
        ]);

        // 5. A bookmark with a name and a summary.
        $rows[] = self::row(1005, $target, $p(4), '2026-03-04 08:05:30', [
            'href'         => $p(4)['post']('bookmarks/reading-list'),
            'url'          => $p(4)['post']('bookmarks/reading-list'),
            'type'         => 'bookmark',
            'name'         => 'Bookmarked: a post worth keeping',
            'summary'      => 'Saved to my reading list for the weekend.',
            'content_text' => 'Saved to my reading list for the weekend.',
            'published'    => '2026-03-04 08:00:00',
            'published_ts' => 1772611200,
            'published_offset' => 7200,
        ]);

        // 6. A plain mention with text only, published in UTC.
        $rows[] = self::row(1006, $target, $p(5), '2026-03-04 20:41:12', [
            'href'         => $p(5)['post']('2026/03/04/weeknotes'),
            'url'          => $p(5)['post']('2026/03/04/weeknotes'),
            'type'         => 'link',
            'name'         => 'Weeknotes 10',
            'content_text' => "Links I enjoyed this week, including $target which made me rethink a few things.",
            'published'    => '2026-03-04 20:40:00',
            'published_ts' => 1772656800,
            'published_offset' => 0,
        ]);

        // 7. A legacy mention: no type, no author details, no dates, no protocol.
        $rows[] = self::row(1007, $target, $p(6), '2014-11-20 03:15:00', [
            'href'         => $p(6)['post']('old-post'),
            'url'          => null,
            'type'         => null,
            'protocol'     => null,
            'author_name'  => '',
            'author_url'   => '',
            'author_photo' => '',
            'published'    => null,
            'published_ts' => null,
            'published_offset' => null,
        ]);

        // 8. A pingback (received before pingback support was removed).
        $rows[] = self::row(1008, $target, $p(7), '2019-06-11 14:02:55', [
            'href'         => $p(7)['post']('blog/pingback'),
            'url'          => $p(7)['post']('blog/pingback'),
            'type'         => 'link',
            'protocol'     => 'pingback',
            'author_name'  => null,
            'author_url'   => null,
            'author_photo' => null,
            'published'    => null,
            'published_ts' => null,
            'published_offset' => null,
        ]);

        // 9 to 12. The four RSVP values.
        $n = 1009;
        foreach (['yes', 'no', 'maybe', 'interested'] as $i => $value) {
            $rows[] = self::row($n, $target, $p($i), sprintf('2026-03-05 %02d:00:00', 10 + $i), [
                'href'         => $p($i)['post']("rsvp/$value"),
                'url'          => $p($i)['post']("rsvp/$value"),
                'type'         => "rsvp-$value",
                'content_text' => match ($value) { 'yes' => 'Count me in!', 'no' => "Sorry, can't make it.", 'maybe' => 'Might be there, depends on the week.', default => 'Following along from afar.' },
                'published'    => sprintf('2026-03-05 %02d:59:00', 9 + $i),
                'published_ts' => 1772704740 + $i * 3600,
                'published_offset' => 0,
            ]);
            $n++;
        }

        // 13. An invite relayed by a bridge: shows as a mention with only an author.
        $rows[] = self::row(1013, $target, $p(4), '2026-03-05 16:45:00', [
            'href'         => 'https://bridge.example/event/social/rsvp/118000000000000002',
            'url'          => 'https://social.example/events/118000000000000002',
            'type'         => 'invite',
            'published'    => '2026-03-05 16:44:00',
            'published_ts' => 1772729040,
            'published_offset' => 0,
        ]);

        // 14. A photo post: photo is a list of URLs.
        $rows[] = self::row(1014, $target, $p(5), '2026-03-06 07:12:00', [
            'href'         => $p(5)['post']('photos/2026/03/06'),
            'url'          => $p(5)['post']('photos/2026/03/06'),
            'type'         => 'link',
            'name'         => 'Two photos from the ride',
            'content_text' => 'Two photos from the ride, both taken near the bridge.',
            'photo'        => json_encode([$photo(1), $photo(2)], JSON_UNESCAPED_SLASHES),
            'published'    => '2026-03-06 07:10:00',
            'published_ts' => 1772781000,
            'published_offset' => -18000,
        ]);

        // 15. A video post.
        $rows[] = self::row(1015, $target, $p(6), '2026-03-06 12:30:00', [
            'href'         => $p(6)['post']('videos/talk'),
            'url'          => $p(6)['post']('videos/talk'),
            'type'         => 'reply',
            'content_text' => 'Recorded my reply as a short video.',
            'video'        => json_encode([$p(6)['post']('media/talk.mp4')], JSON_UNESCAPED_SLASHES),
            'published'    => '2026-03-06 12:29:00',
            'published_ts' => 1772800140,
            'published_offset' => 0,
        ]);

        // 16. An audio post.
        $rows[] = self::row(1016, $target, $p(7), '2026-03-06 18:00:00', [
            'href'         => $p(7)['post']('podcast/episode-12'),
            'url'          => $p(7)['post']('podcast/episode-12'),
            'type'         => 'link',
            'name'         => 'Episode 12: on webmentions',
            'content_text' => 'We talked about this post for about ten minutes near the end of the episode.',
            'audio'        => json_encode([$p(7)['post']('media/episode-12.mp3')], JSON_UNESCAPED_SLASHES),
            'published'    => '2026-03-06 17:55:00',
            'published_ts' => 1772819700,
            'published_offset' => 0,
        ]);

        // 17. An older photo post, where photo was stored as a single string rather than a list.
        $rows[] = self::row(1017, $target, $p(0), '2017-08-21 19:20:00', [
            'href'         => $p(0)['post']('2017/08/21/1'),
            'url'          => $p(0)['post']('2017/08/21/1'),
            'type'         => 'link',
            'photo'        => json_encode($photo(1), JSON_UNESCAPED_SLASHES),
            'published'    => '2017-08-21 19:15:00',
            'published_ts' => 1503342900,
            'published_offset' => -25200,
        ]);

        // 18. A private webmention.
        $rows[] = self::row(1018, $target, $p(1), '2026-03-07 09:00:00', [
            'href'         => $p(1)['post']('private/reply-3'),
            'url'          => $p(1)['post']('private/reply-3'),
            'type'         => 'reply',
            'is_private'   => 1,
            'content'      => '<p>Only you can read this reply; the source page needs a token to fetch.</p>',
            'content_text' => 'Only you can read this reply; the source page needs a token to fetch.',
            'published'    => '2026-03-07 08:58:00',
            'published_ts' => 1772873880,
            'published_offset' => 0,
        ]);

        // 19. A mention received on a site created before 2018: the legacy content shape.
        $rows[] = self::row(1019, $target, $p(2), '2026-03-07 13:33:00', [
            'href'            => $p(2)['post']('2026/03/07/mention'),
            'url'             => $p(2)['post']('2026/03/07/mention'),
            'type'            => 'link',
            'content'         => '<p>Sites set up before 2018 also get <code>content-type</code> and <code>value</code>.</p>',
            'content_text'    => 'Sites set up before 2018 also get content-type and value.',
            'site_created_at' => self::OLD_SITE,
            'published'       => '2026-03-07 13:30:00',
            'published_ts'    => 1772890200,
            'published_offset' => 0,
        ]);

        // 20. A check-in with coins and a canonical URL.
        $rows[] = self::row(1020, $target, $p(3), '2026-03-08 22:10:00', [
            'href'         => $p(3)['post']('checkins/2026/03/08'),
            'url'          => $p(3)['post']('checkins/2026/03/08'),
            'type'         => 'link',
            'name'         => 'Checked in at the coffee place',
            'content_text' => 'Checked in at the coffee place. Third time this week.',
            'swarm_coins'  => 3,
            'relcanonical' => $p(3)['post']('checkins/2026/03/08/'),
            'published'    => '2026-03-08 22:05:00',
            'published_ts' => 1773007500,
            'published_offset' => -28800,
        ]);

        // 21. Long HTML content with the elements the sanitiser allows, and non-Latin text.
        $rows[] = self::row(1021, $target, $p(4), '2026-03-09 06:06:06', [
            'href'         => $p(4)['post']('2026/03/09/long-reply'),
            'url'          => $p(4)['post']('2026/03/09/long-reply'),
            'type'         => 'reply',
            'name'         => 'A longer reply, with pictures and quotes',
            'content'      => '<p>First, a quote:</p><blockquote><p>The best time to plant a tree was twenty years ago.</p></blockquote>'
                . '<p>Then a picture:<br><img src="' . $photo(2) . '" alt="A placeholder photo"></p>'
                . '<p>Some text in other scripts: 日本語のテキスト, טקסט בעברית, and Ελληνικά.</p>'
                . '<ul><li>one point</li><li>another point, with <a href="' . $p(4)['post']('about') . '">a link</a></li></ul>',
            'content_text' => "First, a quote:\n\nThe best time to plant a tree was twenty years ago.\n\nThen a picture:\n\nSome text in other scripts: 日本語のテキスト, טקסט בעברית, and Ελληνικά.\n\none point\nanother point, with a link",
            'published'    => '2026-03-09 06:00:00',
            'published_ts' => 1773036000,
            'published_offset' => 19800,
        ]);

        return array_map(Link::fromRow(...), $rows);
    }

    /**
     * @param  array<string, mixed> $person
     * @param  array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function row(int $id, string $target, array $person, string $received, array $fields): array
    {
        $href = (string) ($fields['href'] ?? '');

        return [
            'id'               => $id,
            'href'             => $href,
            'domain'           => (string) parse_url($href, PHP_URL_HOST),
            'verified'         => 1,
            'protocol'         => 'webmention',
            'endpoint_type'    => 'account',
            'is_private'       => 0,
            'summary'          => null,
            'created_at'       => $received,
            'updated_at'       => $received,
            'page_id'          => 1,
            'author_url'       => $person['url'],
            'author_name'      => $person['name'],
            'author_photo'     => $person['photo'],
            'name'             => null,
            'content'          => null,
            'content_text'     => null,
            'published'        => null,
            'published_ts'     => null,
            'published_offset' => null,
            'url'              => null,
            'relcanonical'     => null,
            'type'             => 'link',
            'is_direct'        => 1,
            'site_id'          => 1,
            'account_id'       => 1,
            'syndication'      => null,
            'token'            => null,
            'swarm_coins'      => null,
            'deleted'          => 0,
            'photo'            => null,
            'video'            => null,
            'audio'            => null,
            'page_href'        => $target,
            'site_created_at'  => self::NEW_SITE,
            ...$fields,
        ];
    }
}
