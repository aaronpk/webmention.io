<?php

declare(strict_types=1);

namespace Webmention\Storage;

/**
 * Filters for listing verified, non-deleted links in the API.
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
    ) {
    }
}
