<?php

declare(strict_types=1);

namespace Webmention\Http;

/**
 * An immutable snapshot of an incoming HTTP request.
 *
 * Built from superglobals in production and from named arguments in tests, so
 * nothing below this layer ever touches $_GET/$_POST/$_SERVER directly.
 *
 * Unlike TinyLogin, array parameters are kept: the public API accepts
 * `target[]=…` and `wm-property[]=…`. Use input() for a single string and
 * inputList() where a list is allowed.
 */
final class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $post
     * @param array<string, string> $headers Keys are lowercased.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly bool $secure = false,
        public readonly string $ip = '127.0.0.1',
    ) {
    }

    public static function fromGlobals(bool $trustProxy = false): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $server => $name) {
            if (isset($_SERVER[$server])) {
                $headers[$name] = (string) $_SERVER[$server];
            }
        }

        $secure = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';
        $ip     = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        // Only honoured when explicitly configured, so a spoofed header on a
        // direct connection cannot change what the app believes.
        if ($trustProxy) {
            $secure = $secure || strtolower($headers['x-forwarded-proto'] ?? '') === 'https';
            if (isset($headers['x-forwarded-for'])) {
                $ip = trim(explode(',', $headers['x-forwarded-for'])[0]);
            }
        }

        return new self(
            method:  $method,
            path:    self::normalizePath($path),
            query:   $_GET,
            post:    $_POST,
            headers: $headers,
            body:    (string) file_get_contents('php://input'),
            secure:  $secure,
            ip:      $ip,
        );
    }

    /** Collapse a trailing slash so /dashboard/ and /dashboard are the same route. */
    private static function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** A query string parameter, or null if it is absent or not a single value. */
    public function query(string $name): ?string
    {
        return self::scalar($this->query[$name] ?? null);
    }

    /** A form body parameter, or null if it is absent or not a single value. */
    public function post(string $name): ?string
    {
        return self::scalar($this->post[$name] ?? null);
    }

    /** POST body first, then query string, matching how Sinatra merged params. */
    public function input(string $name): ?string
    {
        return $this->post($name) ?? $this->query($name);
    }

    /** True if the parameter was sent at all, in either the body or the query string. */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->post) || array_key_exists($name, $this->query);
    }

    /**
     * A parameter that may be repeated. `x=a`, `x[]=a&x[]=b` and `x[0]=a&x[1]=b`
     * all work. Nested arrays and empty strings are dropped.
     *
     * @return list<string>
     */
    public function inputList(string $name): array
    {
        $value = $this->post[$name] ?? $this->query[$name] ?? null;

        if (!is_array($value)) {
            $single = self::scalar($value);

            return $single === null || $single === '' ? [] : [$single];
        }

        $out = [];
        foreach ($value as $item) {
            $item = self::scalar($item);
            if ($item !== null && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Whether a browser sent this request from a page on this site, for forms
     * that must not be submittable from elsewhere but have no session yet.
     * Modern browsers say so in Sec-Fetch-Site; older ones are judged by the
     * Origin (or Referer) header. A request naming neither is refused.
     */
    public function fromSameOrigin(string $baseUrl): bool
    {
        $site = strtolower((string) $this->header('sec-fetch-site'));
        if ($site !== '') {
            return in_array($site, ['same-origin', 'none'], true);
        }

        $sent = $this->header('origin') ?? $this->header('referer');
        if ($sent === null || $sent === '') {
            return false;
        }

        $expected = parse_url($baseUrl);
        $actual   = parse_url($sent);

        return is_array($expected) && is_array($actual)
            && strtolower((string) ($actual['scheme'] ?? '')) === strtolower((string) ($expected['scheme'] ?? ''))
            && strtolower((string) ($actual['host'] ?? '')) === strtolower((string) ($expected['host'] ?? ''))
            && ($actual['port'] ?? null) === ($expected['port'] ?? null);
    }

    /** The old app switched JSON responses to an HTML page for browsers. */
    public function acceptsHtml(): bool
    {
        return str_contains((string) $this->header('accept'), 'text/html');
    }

    private static function scalar(mixed $value): ?string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}
