<?php

declare(strict_types=1);

namespace Webmention\Http;

/**
 * An immutable HTTP response. Headers may repeat (Set-Cookie), so values are
 * stored as lists.
 *
 * A streamed response has no body string; its writer runs when the response
 * is sent, after the headers, and echoes as it goes. Used for exports too
 * large to assemble in memory.
 */
final class Response
{
    /** @var (callable(): void)|null */
    private $writer = null;

    /** @param array<string, list<string>> $headers */
    private function __construct(
        public readonly int $status,
        public readonly string $body,
        private readonly array $headers = [],
    ) {
    }

    /**
     * @param callable(): void        $writer  Echoes the body; may call flush().
     * @param array<string, string>   $headers
     */
    public static function stream(callable $writer, array $headers = [], int $status = 200): self
    {
        $response         = self::make($status, '', $headers);
        $response->writer = $writer;

        return $response;
    }

    public function isStreamed(): bool
    {
        return $this->writer !== null;
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

        return $this->copyWith($headers);
    }

    public function withoutHeader(string $name): self
    {
        $headers = $this->headers;
        unset($headers[strtolower($name)]);

        return $this->copyWith($headers);
    }

    /** @param array<string, list<string>> $headers */
    private function copyWith(array $headers): self
    {
        $copy         = new self($this->status, $this->body, $headers);
        $copy->writer = $this->writer;

        return $copy;
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

        if ($this->writer !== null) {
            ($this->writer)();

            return;
        }

        echo $this->body;
    }

    /** For tests: the body a streamed response would send. */
    public function capture(): string
    {
        if ($this->writer === null) {
            return $this->body;
        }

        ob_start();
        try {
            ($this->writer)();
        } finally {
            $out = (string) ob_get_clean();
        }

        return $out;
    }
}
