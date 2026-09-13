<?php

declare(strict_types=1);

namespace Webmention\Model;

final class Page
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $href,
        public readonly int $accountId,
        public readonly int $siteId,
        public readonly ?string $type,
        public readonly ?string $name,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:        (int) $row['id'],
            href:      $row['href'] === null ? null : (string) $row['href'],
            accountId: (int) $row['account_id'],
            siteId:    (int) $row['site_id'],
            type:      $row['type'] === null ? null : (string) $row['type'],
            name:      $row['name'] === null ? null : (string) $row['name'],
        );
    }
}
