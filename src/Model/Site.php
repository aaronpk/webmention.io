<?php

declare(strict_types=1);

namespace Webmention\Model;

final class Site
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $domain,
        public readonly int $accountId,
        public readonly ?string $callbackUrl,
        public readonly ?string $callbackSecret,
        public readonly bool $archiveAvatars,
        public readonly ?string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (int) $row['id'],
            domain:         $row['domain'] === null ? null : (string) $row['domain'],
            accountId:      (int) $row['account_id'],
            callbackUrl:    $row['callback_url'] === null ? null : (string) $row['callback_url'],
            callbackSecret: $row['callback_secret'] === null ? null : (string) $row['callback_secret'],
            // The column defaults to 1; NULL was treated as falsy by the Ruby app.
            archiveAvatars: (bool) $row['archive_avatars'],
            createdAt:      $row['created_at'] === null ? null : (string) $row['created_at'],
        );
    }
}
