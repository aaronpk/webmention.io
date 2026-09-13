<?php

declare(strict_types=1);

namespace Webmention\Http;

/**
 * An immutable HTTP response. Headers may repeat (Set-Cookie), so values are
 * stored as lists.
 */
final class Response
{
    /** @param array<string, list<string>> $headers */
    private function __construct(
        public readonly int $status,
        public readonly string $body,
        private readonly array $headers = [],
    ) {
    }

    /** @param array<string, string> $headers */
    public static function make(int $status = 200, string $body = '', array $headers = []): self
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = [$value];
        }

        return new self($status, $body, $normalized);
    }

    public static function html(string $body, int $status = 200): self
    {
        return self::make($status, $body, ['content-type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $body, int $status = 200): self
    {
        return self::make($status, $body, ['content-type' => 'text/plain; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return self::make($status, '', ['location' => $location]);
    }

    /** A 303 redirect, for use after a POST. */
    public static function seeOther(string $location): self
    {
        return self::redirect($location, 303);
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[strtolower($name)] = [$value];

        return new self($this->status, $this->body, $headers);
    }

    public function withoutHeader(string $name): self
    {
        $headers = $this->headers;
        unset($headers[strtolower($name)]);

        return new self($this->status, $this->body, $headers);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /** @return array<string, list<string>> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $values) {
                $first = true;
                foreach ($values as $value) {
                    header($name . ': ' . $value, $first);
                    $first = false;
                }
            }
        }

        echo $this->body;
    }
}
