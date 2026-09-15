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
        public readonly ?string $verifiedAt = null,
        public readonly ?string $verificationCheckedAt = null,
        public readonly ?string $verificationError = null,
        /** Hold policy for new mentions: null/"off", "first" or "all". See Moderation. */
        public readonly ?string $moderation = null,
        /** When the owner archived it: it refuses new webmentions but keeps its own. */
        public readonly ?string $archivedAt = null,
    ) {
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    /** Whether the site has proved it belongs to its account (see SiteVerifier). */
    public function isVerified(): bool
    {
        return $this->verifiedAt !== null;
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
            verifiedAt:            isset($row['verified_at']) ? (string) $row['verified_at'] : null,
            verificationCheckedAt: isset($row['verification_checked_at']) ? (string) $row['verification_checked_at'] : null,
            verificationError:     isset($row['verification_error']) ? (string) $row['verification_error'] : null,
            moderation:            isset($row['moderation']) ? (string) $row['moderation'] : null,
            archivedAt:            isset($row['archived_at']) ? (string) $row['archived_at'] : null,
        );
    }
}
