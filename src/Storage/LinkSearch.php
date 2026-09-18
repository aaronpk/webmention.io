<?php

declare(strict_types=1);

namespace Webmention\Storage;

/**
 * Filters for listing links, in the API and in the signed-in mention browser.
 *
 * The API only ever lists published mentions (verified and not deleted), which
 * is the default status. The browser also asks for the ones held for review,
 * hidden by a mute rule, or deleted.
 *
 * Private webmentions (received with an authorization code) are only listed
 * when includePrivate is set, which the API does for the owning account's
 * token and never for a public target query.
 */
final class LinkSearch
{
    /**
     * @param list<int>|null $pageIds           Null means "not filtered by page".
     * @param list<string>   $types             Empty means any type (unless includeUnlabelled).
     * @param bool           $includeUnlabelled Also match rows whose type is NULL or not one of
     *                                          Jf2Format::LABELLED_TYPES: everything shown as mention-of.
     * @param list<string>   $fragments         Only mentions sent to one of these #fragments of the target.
     * @param string         $status            One of STATUSES.
     * @param string|null    $sourceDomain      Only mentions whose source is on this host (links.domain, exact).
     */
    public function __construct(
        public readonly ?int $accountId = null,
        public readonly ?int $siteId = null,
        public readonly ?array $pageIds = null,
        public readonly array $types = [],
        public readonly ?string $createdAfter = null,
        public readonly ?int $idAfter = null,
        public readonly string $sortBy = 'created',
        public readonly bool $descending = true,
        public readonly int $limit = 20,
        public readonly int $offset = 0,
        public readonly bool $includePrivate = false,
        public readonly bool $includeUnlabelled = false,
        public readonly array $fragments = [],
        public readonly string $status = self::PUBLISHED,
        public readonly ?string $sourceDomain = null,
    ) {
    }

    public const PUBLISHED = 'published';
    public const PENDING   = 'pending';
    public const HIDDEN    = 'hidden';
    public const DELETED   = 'deleted';

    public const STATUSES = [self::PUBLISHED, self::PENDING, self::HIDDEN, self::DELETED];
}
