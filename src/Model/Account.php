<?php

declare(strict_types=1);

namespace Webmention\Model;

final class Account
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $username,
        public readonly ?string $domain,
        public readonly ?string $token,
        public readonly ?string $apertureUri,
        public readonly ?string $apertureToken,
        public readonly ?string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:            (int) $row['id'],
            username:      $row['username'] === null ? null : (string) $row['username'],
            domain:        $row['domain'] === null ? null : (string) $row['domain'],
            token:         $row['token'] === null ? null : (string) $row['token'],
            apertureUri:   $row['aperture_uri'] === null ? null : (string) $row['aperture_uri'],
            apertureToken: $row['aperture_token'] === null ? null : (string) $row['aperture_token'],
            createdAt:     $row['created_at'] === null ? null : (string) $row['created_at'],
        );
    }
}
