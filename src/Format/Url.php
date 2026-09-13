<?php

declare(strict_types=1);

namespace Webmention\Format;

/**
 * URL helpers.
 *
 * absolutize() is a port of the AbsoluteUri helper the Ruby app copied from
 * the microformats gem, so stored and returned URLs resolve the same way.
 */
final class Url
{
    /**
     * Resolve $relative against $base.
     *
     * Absolute http(s) URLs and bare fragments are returned without
     * normalization, as before. Anything else is resolved, percent-encoded
     * where it contains characters not allowed in a URL (such as emoji), and
     * has its scheme and host lowercased.
     */
    public static function absolutize(?string $relative, ?string $base): ?string
    {
        $base     = $base === null ? null : trim($base);
        $relative = $relative === null ? null : trim($relative);

        if ($base === null) {
            return $relative;
        }
        if ($relative === null || $relative === '') {
            return $base;
        }
        if (preg_match('#^https?://#', $relative) === 1) {
            return $relative;
        }
        if (str_starts_with($relative, '#')) {
            return $base . $relative;
        }

        $resolved = \Mf2\resolveUrl(self::escape($base), self::escape($relative));

        return self::normalize($resolved);
    }

    /** Percent-encode bytes that may not appear literally in a URL. */
    public static function escape(string $url): string
    {
        return (string) preg_replace_callback(
            "/[^A-Za-z0-9\\-_.!~*'();\\/?:@&=+$,\\[\\]#%]/",
            static fn (array $m): string => rawurlencode($m[0]),
            $url,
        );
    }

    /** Lowercase the scheme and host, and give an authority with no path a "/". */
    public static function normalize(string $url): string
    {
        if (preg_match('#^([a-z][a-z0-9+.\-]*)://([^/?\#]*)(.*)$#is', $url, $m) !== 1) {
            return $url;
        }

        [, $scheme, $authority, $rest] = $m;

        $authority = preg_replace_callback(
            '/(^|@)([^@:]+)/',
            static fn (array $h): string => $h[1] . strtolower($h[2]),
            $authority,
        );

        if ($rest === '' || $rest[0] === '?' || $rest[0] === '#') {
            $rest = '/' . $rest;
        }

        return strtolower($scheme) . '://' . $authority . $rest;
    }

    /** The host of a URL, lowercased, or null. Tolerates non-ASCII characters. */
    public static function host(string $url): ?string
    {
        $host = parse_url(self::escape($url), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /** True for an http or https URL that has a host. */
    public static function isHttp(string $url): bool
    {
        $parts = parse_url(self::escape($url));

        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
    }

    /** The Ruby app's `blank?`: null, empty, or only whitespace. */
    public static function blank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }
}
