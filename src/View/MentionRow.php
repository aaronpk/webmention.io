<?php

declare(strict_types=1);

namespace Webmention\View;

use Webmention\Controllers\ApiController;
use Webmention\Format\Jf2Format;
use Webmention\Format\Url;
use Webmention\Model\Link;
use Webmention\Storage\LinkSearch;

/**
 * What templates/_row.php needs to show one mention, on the dashboard, in
 * the review queue, in the browser, and on the delete preview.
 */
final class MentionRow
{
    /** How a mention reads in a list: "liked", "replied to", … */
    public static function kind(?string $type): string
    {
        return match (Jf2Format::relation($type)) {
            'like-of'     => 'liked',
            'repost-of'   => 'reposted',
            'in-reply-to' => 'replied to',
            'bookmark-of' => 'bookmarked',
            'rsvp'        => 'RSVPed ' . substr((string) $type, 5) . ' to',
            default       => 'mentioned',
        };
    }

    /** One of LinkSearch::STATUSES: which list a row belongs in. */
    public static function status(Link $link): string
    {
        return match (true) {
            $link->deleted              => LinkSearch::DELETED,
            $link->status === 'pending' => LinkSearch::PENDING,
            $link->status === 'hidden'  => LinkSearch::HIDDEN,
            default                     => LinkSearch::PUBLISHED,
        };
    }

    /** @return array<string, mixed> */
    public static function row(Link $link): array
    {
        $text    = trim(preg_replace('/\s+/u', ' ', (string) $link->contentText) ?? '');
        $excerpt = $text === '' ? null : (mb_strlen($text) > 200 ? rtrim(mb_substr($text, 0, 200)) . '…' : $text);
        $target  = (string) $link->targetHref;
        $parts   = parse_url(Url::escape($target)) ?: [];
        $path    = ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return [
            'id'          => $link->id,
            'status'      => self::status($link),
            'href'        => (string) $link->href,
            'source_url'  => ApiController::safeUrl($link->href),
            'source_host' => (string) (Url::host((string) $link->href) ?? $link->domain),
            'target'      => $target,
            'target_url'  => ApiController::safeUrl($link->targetHref),
            // Shown as host + path, so a row says which site it landed on without the scheme.
            'target_host' => Url::host($target) ?? '',
            'target_path' => Url::host($target) === null ? $target : ($path !== '' ? $path : '/'),
            'author_name' => (string) $link->authorName,
            'author_url'  => Url::blank($link->authorUrl) ? null : ApiController::safeUrl($link->authorUrl),
            'author_host' => Url::blank($link->authorUrl) ? null : Url::host((string) $link->authorUrl),
            'photo'       => Url::blank($link->authorPhoto) ? null : ApiController::safeUrl(Jf2Format::avatarUrl($link->authorPhoto)),
            'type'        => Jf2Format::relation($link->type),
            'kind'        => self::kind($link->type),
            'name'        => Url::blank($link->name) ? null : $link->name,
            'excerpt'     => $excerpt,
            'published'   => Jf2Format::publishedDate($link)?->format('M j, Y'),
            'received'    => $link->createdDate()?->format('M j, Y'),
            'deleted_on'  => $link->deleted ? $link->updatedDate()?->format('M j, Y') : null,
            'delete_url'  => '/delete?source=' . rawurlencode((string) $link->href) . '&id=' . $link->id,
            // Set by the browser for a hidden mention: the mute rule that covers it.
            'rule'        => null,
        ];
    }
}
