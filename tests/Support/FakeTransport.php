<?php

declare(strict_types=1);

namespace Webmention\Tests\Support;

use p3k\HTTP\Transport;

/**
 * An HTTP transport that never touches the network.
 *
 * GET and HEAD for http://{host}/{path} serve tests/fixtures/{host}/{path}.html
 * ("/" serves index.html). Anything else is a 404 unless a response was
 * registered with respond(). Every request is recorded.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, body: string|null, headers: list<string>}> */
    public array $requests = [];

    /** @var array<string, array{code: int, body: string, headers: array<string, string>, error: string}> */
    private array $responses = [];

    public function __construct(private readonly string $fixtures)
    {
    }

    /** @param array<string, string> $headers */
    public function respond(string $method, string $url, int $code, string $body = '', array $headers = [], string $error = ''): void
    {
        $this->responses[strtoupper($method) . ' ' . $url] = [
            'code'    => $code,
            'body'    => $body,
            'headers' => $headers,
            'error'   => $error,
        ];
    }

    /** @return list<array{method: string, url: string, body: string|null, headers: list<string>}> */
    public function posts(string $url): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $r): bool => $r['method'] === 'POST' && $r['url'] === $url,
        ));
    }

    public function get($url, $headers = [])
    {
        return $this->handle('GET', $url, null, $headers);
    }

    public function post($url, $body, $headers = [])
    {
        return $this->handle('POST', $url, (string) $body, $headers);
    }

    public function put($url, $body, $headers = [])
    {
        return $this->handle('PUT', $url, (string) $body, $headers);
    }

    public function head($url, $headers = [])
    {
        $response = $this->handle('HEAD', $url, null, $headers);
        unset($response['body']);

        return $response;
    }

    public function set_timeout($timeout)
    {
    }

    public function set_max_redirects($max_redirects)
    {
    }

    /** @param list<string> $headers */
    private function handle(string $method, string $url, ?string $body, array $headers): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];

        $registered = $this->responses["$method $url"]
            ?? ($method === 'HEAD' ? ($this->responses["GET $url"] ?? null) : null);

        if ($registered !== null) {
            return $this->build($url, $registered['code'], $registered['body'], $registered['headers'], $registered['error']);
        }

        if (in_array($method, ['GET', 'HEAD'], true) && ($file = $this->fixtureFor($url)) !== null) {
            return $this->build($url, 200, (string) file_get_contents($file), ['Content-Type' => 'text/html; charset=utf-8']);
        }

        return $this->build($url, 404, 'Not Found', ['Content-Type' => 'text/html']);
    }

    private function fixtureFor(string $url): ?string
    {
        $parts = parse_url($url);
        $host  = $parts['host'] ?? '';
        $path  = trim($parts['path'] ?? '', '/');

        $file = $this->fixtures . '/' . $host . '/' . ($path === '' ? 'index' : $path) . '.html';

        return is_file($file) ? $file : null;
    }

    /** @param array<string, string> $headers */
    private function build(string $url, int $code, string $body, array $headers, string $error = ''): array
    {
        $lines = ["HTTP/1.1 $code"];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }

        return [
            'code'              => $code,
            'header'            => implode("\r\n", $lines),
            'body'              => $body,
            'error'             => $error,
            'error_description' => $error === '' ? '' : "Simulated $error",
            'url'               => $url,
            'debug'             => '',
        ];
    }
}
