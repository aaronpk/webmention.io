<?php

declare(strict_types=1);

namespace Webmention\Model;

/**
 * One POST to a site's callback URL: what was sent and what came back
 * (issue 231). kind is "mention", "deleted" or "test" (sent by hand from
 * the site's settings page). A failed mention or deletion is tried again
 * (see WebHooks); every try is its own row.
 */
final class WebhookDelivery
{
    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
        public readonly ?int $linkId,
        public readonly string $kind,
        public readonly string $url,
        public readonly ?int $statusCode,
        public readonly ?string $error,
        public readonly int $durationMs,
        public readonly string $requestBody,
        public readonly ?string $responseBody,
        public readonly string $createdAt,
        /** 1 for the first try; retries of a failed mention or deletion count up from there. */
        public readonly int $attempt = 1,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:           (int) $row['id'],
            siteId:       (int) $row['site_id'],
            linkId:       $row['link_id'] === null ? null : (int) $row['link_id'],
            kind:         (string) $row['kind'],
            url:          (string) $row['url'],
            statusCode:   $row['status_code'] === null ? null : (int) $row['status_code'],
            error:        $row['error'] === null || $row['error'] === '' ? null : (string) $row['error'],
            durationMs:   (int) $row['duration_ms'],
            requestBody:  (string) $row['request_body'],
            responseBody: $row['response_body'] === null ? null : (string) $row['response_body'],
            createdAt:    (string) $row['created_at'],
            attempt:      max(1, (int) ($row['attempt'] ?? 1)),
        );
    }

    /** The endpoint took it: no transport error and a 2xx status. */
    public function succeeded(): bool
    {
        return $this->error === null && $this->statusCode !== null && $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /** What went wrong, or the status, in a few words. */
    public function result(): string
    {
        if ($this->error !== null) {
            return $this->error;
        }

        return $this->statusCode === null ? 'No response' : 'HTTP ' . $this->statusCode;
    }
}
