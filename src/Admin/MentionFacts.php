<?php

declare(strict_types=1);

namespace Webmention\Admin;

use Webmention\Controllers\ApiController;
use Webmention\Format\Url;
use Webmention\Model\Link;
use Webmention\View\MentionRow;

/**
 * One mention as an admin needs to see it: the structural facts, in full,
 * whoever owns it.
 *
 * A private webmention is the exception. Its source, target and state are
 * shown, because that is what debugging needs, but its author and its content
 * are not: they were sent to one person on purpose. Everything else is
 * already public on the web.
 */
final class MentionFacts
{
    /** @return array<string, mixed> */
    public static function of(Link $link): array
    {
        $private = $link->isPrivate;
        $text    = trim(preg_replace('/\s+/u', ' ', (string) $link->contentText) ?? '');

        return [
            'id'          => $link->id,
            'source'      => (string) $link->href,
            'source_url'  => ApiController::safeUrl($link->href),
            'domain'      => $link->domain,
            'target'      => (string) $link->targetHref,
            'target_url'  => ApiController::safeUrl($link->targetHref),
            'fragment'    => $link->targetFragment,
            'status'      => MentionRow::status($link),
            'verified'    => $link->verified,
            'private'     => $private,
            'type'        => $link->type,
            'kind'        => MentionRow::kind($link->type),
            'protocol'    => $link->protocol,
            'endpoint'    => $link->endpointType,
            'direct'      => $link->isDirect,
            'page_id'     => $link->pageId,
            'site_id'     => $link->siteId,
            'account_id'  => $link->accountId,
            'token'       => $link->token,
            'created_at'  => $link->createdAt,
            'updated_at'  => $link->updatedAt,
            'published'   => $link->published,
            'author_name' => $private ? null : $link->authorName,
            'author_url'  => $private || Url::blank($link->authorUrl) ? null : ApiController::safeUrl($link->authorUrl),
            'name'        => $private ? null : $link->name,
            'excerpt'     => $private || $text === '' ? null : (mb_strlen($text) > 300 ? rtrim(mb_substr($text, 0, 300)) . '…' : $text),
        ];
    }

    /**
     * @param  list<Link> $links
     * @return list<array<string, mixed>>
     */
    public static function all(array $links): array
    {
        return array_map(self::of(...), $links);
    }
}
