<?php

declare(strict_types=1);

namespace Webmention\Format;

use Webmention\Model\Link;

/**
 * The jf2 representation of a link, used by /api/mentions.jf2, the webhook
 * payload and the webmention status endpoint.
 *
 * Port of Formats.build_jf2_from_link. Key order matches the old output.
 */
final class Jf2Format
{
    /**
     * Sites created after this switched to jf2's `html`/`text` content
     * properties only. Older sites also get the legacy content-type/value pair.
     */
    private const CONTENT_DEPRECATION_DATE = '2018-02-26 17:00:00';

    /** @param list<Link> $links */
    public static function feed(array $links): array
    {
        return [
            'type'     => 'feed',
            'name'     => 'Webmentions',
            'children' => array_map(self::entry(...), $links),
        ];
    }

    /** @return array<string, mixed> */
    public static function entry(Link $link): array
    {
        $published = null;
        if (($date = self::publishedDate($link)) !== null) {
            $published = $date->format('Y-m-d\TH:i:s');
            if ($link->publishedOffset !== null) {
                $published .= Link::offsetString($link->publishedOffset);
            }
        }

        $jf2 = [
            'type'   => 'entry',
            'author' => [
                'type'  => 'card',
                'name'  => $link->authorName,
                'photo' => self::avatarUrl($link->authorPhoto),
                'url'   => $link->authorUrl,
            ],
            'url'          => $link->absoluteUrl(),
            'published'    => $published,
            'wm-received'  => $link->createdDate()?->format('Y-m-d\TH:i:s\Z'),
            'wm-id'        => $link->id,
            'wm-source'    => $link->href,
            'wm-target'    => $link->targetHref,
            'wm-protocol'  => $link->protocol,
        ];

        if (!Url::blank($link->name)) {
            $jf2['name'] = $link->name;
        }

        if (($syndications = $link->syndications()) !== null) {
            $jf2['syndication'] = $syndications;
        }

        if (!Url::blank($link->summary)) {
            $jf2['summary'] = [
                'content-type' => 'text/plain',
                'value'        => $link->summary,
            ];
        }

        foreach (['photo' => $link->photo, 'video' => $link->video, 'audio' => $link->audio] as $key => $json) {
            if (!Url::blank($json)) {
                $decoded = json_decode((string) $json, true);
                if ($decoded !== null) {
                    $jf2[$key] = $decoded;
                }
            }
        }

        $modern = $link->siteCreatedAt !== null && $link->siteCreatedAt > self::CONTENT_DEPRECATION_DATE;

        if (!Url::blank($link->content)) {
            $jf2['content'] = $modern
                ? ['html' => $link->content, 'text' => $link->contentText]
                : ['content-type' => 'text/html', 'value' => $link->content, 'html' => $link->content, 'text' => $link->contentText];
        } elseif (!Url::blank($link->contentText)) {
            $jf2['content'] = $modern
                ? ['text' => $link->contentText]
                : ['content-type' => 'text/plain', 'value' => $link->contentText, 'text' => $link->contentText];
        }

        if ($link->swarmCoins !== null) {
            $jf2['swarm-coins'] = $link->swarmCoins;
        }

        $relation = self::relation($link->type);

        if ($relation === 'rsvp') {
            $jf2['rsvp']        = substr((string) $link->type, 5);
            $jf2['in-reply-to'] = $link->targetHref;
        } else {
            $jf2[$relation] = $link->targetHref;
        }

        $jf2['wm-property'] = $relation;
        $jf2['wm-private']  = $link->isPrivate;

        if ($link->relcanonical !== null) {
            $jf2['rels'] = ['canonical' => $link->relcanonical];
        }

        return $jf2;
    }

    /** The jf2 property linking a post of this type to its target. */
    public static function relation(?string $type): string
    {
        return match ($type) {
            'like'     => 'like-of',
            'repost'   => 'repost-of',
            'reply'    => 'in-reply-to',
            'bookmark' => 'bookmark-of',
            'rsvp-yes', 'rsvp-no', 'rsvp-maybe', 'rsvp-interested' => 'rsvp',
            default    => 'mention-of',
        };
    }

    /** Archived avatars moved from webmention.io/avatar/ to their own CDN hostname. */
    public static function avatarUrl(?string $url): ?string
    {
        return $url === null ? null : str_replace('https://webmention.io/avatar/', 'https://avatars.webmention.io/', $url);
    }

    /** Null for a missing or zero date, which MySQL allows with an empty sql_mode. */
    public static function publishedDate(Link $link): ?\DateTimeImmutable
    {
        if ($link->published === null || str_starts_with($link->published, '0000-00-00')) {
            return null;
        }

        return $link->publishedDate();
    }
}
