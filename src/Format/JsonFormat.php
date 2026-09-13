<?php

declare(strict_types=1);

namespace Webmention\Format;

use Webmention\Model\Link;

/**
 * The original (pre-jf2) JSON representation, used by /api/mentions and
 * /api/mentions.json. Port of Formats.links_to_json.
 */
final class JsonFormat
{
    /** @param list<Link> $links */
    public static function links(array $links): array
    {
        return ['links' => array_map(self::link(...), $links)];
    }

    /** @return array<string, mixed> */
    public static function link(Link $link): array
    {
        $data = [];

        if ($link->hasAuthorInfo()) {
            $author = [];
            if ($link->authorName !== null) {
                $author['name'] = $link->authorName;
            }
            $author['url'] = Url::blank($link->authorUrl)
                ? null
                : Url::absolutize($link->authorUrl, $link->href);
            $author['photo'] = Url::blank($link->authorPhoto)
                ? null
                : Jf2Format::avatarUrl(Url::absolutize($link->authorPhoto, $link->href));
            $data['author'] = $author;
        }

        $data['url']          = $link->absoluteUrl();
        $data['name']         = $link->name;
        $data['content']      = Url::blank($link->content) ? $link->contentText : $link->content;
        $data['published']    = Jf2Format::publishedDate($link)?->format('Y-m-d\TH:i:sP');
        $data['published_ts'] = $link->publishedTs;

        if (in_array($link->type, ['rsvp-yes', 'rsvp-no', 'rsvp-maybe'], true)) {
            $data['rsvp'] = substr((string) $link->type, 5);
        }

        if ($link->swarmCoins !== null) {
            $data['swarm_coins'] = $link->swarmCoins;
        }

        $obj = [
            'source'        => $link->href,
            'verified'      => $link->verified === true,
            'verified_date' => $link->updatedDate()?->format('Y-m-d\TH:i:sP'),
            'id'            => $link->id,
            'private'       => $link->isPrivate,
            'data'          => $data,
            'activity'      => [
                'type' => $link->type === null ? null : preg_replace('/rsvp-.*/', 'rsvp', $link->type),
            ],
        ];

        if ($link->relcanonical !== null) {
            $obj['rels'] = ['canonical' => $link->relcanonical];
        }

        $obj['target'] = $link->targetHref;

        return $obj;
    }
}
