<?php

declare(strict_types=1);

namespace Webmention\Storage;

/**
 * Filters for listing verified, non-deleted links in the API.
 *
 * Private webmentions (received with an authorization code) are only listed
 * when includePrivate is set, which the API does for the owning account's
 * token and never for a public target query.
 */
final class LinkSearch
{
    /**
     * @param list<int>|null $pageIds Null means "not filtered by page".
     * @param list<string>   $types   Empty means any type.
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
    ) {
    }
}
