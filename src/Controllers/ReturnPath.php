<?php

declare(strict_types=1);

namespace Webmention\Controllers;

/**
 * Where a review action goes back to. Forms carry the page they were on in a
 * `back` field; only a few of our own pages are accepted, with the query
 * string rebuilt from known keys, so the value can never send anyone
 * elsewhere.
 */
final class ReturnPath
{
    /** @var array<string, list<string>> Path => query keys it may carry. */
    private const ALLOWED = [
        '/dashboard'  => [],
        '/moderation' => ['page'],
        '/mentions'   => ['status', 'site', 'type', 'domain', 'page'],
        '/sources'    => [],
    ];

    public static function resolve(?string $back, string $default = '/dashboard'): string
    {
        $back  = (string) $back;
        $path  = (string) parse_url($back, PHP_URL_PATH);
        $query = (string) parse_url($back, PHP_URL_QUERY);

        if (!isset(self::ALLOWED[$path]) || $back !== $path . ($query === '' ? '' : "?$query")) {
            return $default;
        }

        parse_str($query, $given);
        $kept = [];
        foreach (self::ALLOWED[$path] as $key) {
            if (!isset($given[$key]) || !is_string($given[$key]) || $given[$key] === '') {
                continue;
            }
            if ($key === 'page' && (int) $given[$key] <= 0) {
                continue; // the first page is the bare path
            }
            $kept[$key] = $given[$key];
        }

        return $path . ($kept === [] ? '' : '?' . http_build_query($kept));
    }

    /** The path with a notice added, for a redirect. */
    public static function withNotice(string $path, string $notice): string
    {
        return $path . (str_contains($path, '?') ? '&' : '?') . 'notice=' . rawurlencode($notice);
    }
}
