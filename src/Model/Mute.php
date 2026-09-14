<?php

declare(strict_types=1);

namespace Webmention\Model;

use Webmention\Format\Url;

/**
 * A mute rule: mentions matching it are stored but hidden (issue 85).
 *
 * kind "source" matches the mention's own URL, "author" its author URL. A
 * pattern containing "://" is a URL prefix; anything else is a hostname and
 * matches that host and its subdomains.
 */
final class Mute
{
    public const KINDS = ['source', 'author'];

    public function __construct(
        public readonly int $id,
        public readonly int $accountId,
        public readonly string $kind,
        public readonly string $pattern,
        public readonly ?string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:        (int) $row['id'],
            accountId: (int) $row['account_id'],
            kind:      (string) $row['kind'],
            pattern:   (string) $row['pattern'],
            createdAt: $row['created_at'] === null ? null : (string) $row['created_at'],
        );
    }

    public function isPrefix(): bool
    {
        return str_contains($this->pattern, '://');
    }

    /** Whether a mention with this source URL and author URL falls under the rule. */
    public function matches(?string $sourceUrl, ?string $authorUrl): bool
    {
        $url = $this->kind === 'author' ? $authorUrl : $sourceUrl;
        if ($url === null || $url === '') {
            return false;
        }

        if ($this->isPrefix()) {
            return str_starts_with(strtolower($url), strtolower($this->pattern));
        }

        $host = Url::host($url);

        return $host !== null && ($host === $this->pattern || str_ends_with($host, '.' . $this->pattern));
    }

    /**
     * A pattern as typed, cleaned up: a URL prefix is kept as given (minus
     * whitespace); a hostname is lowercased and stripped of a scheme-less
     * path. Null when it is neither.
     */
    public static function normalizePattern(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        if (str_contains($input, '://')) {
            return Url::isHttp($input) && strlen($input) <= 255 ? $input : null;
        }

        $host = strtolower(explode('/', $input)[0]);

        return preg_match('/^[a-z0-9.\-_]+$/', $host) === 1 && str_contains($host, '.') ? $host : null;
    }

    /** What the rule reads like on the Blocklists page. */
    public function describe(): string
    {
        return ($this->kind === 'author' ? 'Author' : 'Source') . ($this->isPrefix() ? ' URLs starting with ' : ' on ') . $this->pattern;
    }
}
