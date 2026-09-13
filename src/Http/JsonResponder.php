<?php

declare(strict_types=1);

namespace Webmention\Http;

use Webmention\View\Template;

/**
 * JSON responses for the public endpoints. Port of the old json_response:
 *
 * - a `jsonp` parameter wraps the JSON in a callback
 * - a browser (Accept: text/html) gets a readable HTML page instead
 * - everything is uncacheable and allowed cross-origin
 *
 * Data may be an array or an object; use stdClass for any value that must
 * encode as `{}` when empty.
 */
final class JsonResponder
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(private readonly Template $view)
    {
    }

    /**
     * @param array<string, mixed>|object $data
     * @param array<string, string>       $headers
     */
    public function respond(Request $request, int $status, array|object $data, array $headers = []): Response
    {
        $callback = $request->input('jsonp');

        if ($callback !== null && $callback !== '' && self::validCallback($callback)) {
            $response = Response::make($status, $callback . '(' . self::encode($data) . ')', [
                'content-type' => 'text/javascript;charset=UTF-8',
            ]);
        } elseif ($request->acceptsHtml()) {
            $response = Response::make($status, $this->renderHtml((array) $data), [
                'content-type' => 'text/html;charset=UTF-8',
            ]);
        } else {
            $response = Response::make($status, self::encode($data), [
                'content-type' => 'application/json;charset=UTF-8',
            ]);
        }

        $response = $response
            ->withHeader('cache-control', 'no-store')
            ->withHeader('access-control-allow-origin', '*');

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /** @param array<string, mixed> $data */
    private function renderHtml(array $data): string
    {
        $string = static fn (string $key): ?string => is_string($data[$key] ?? null) ? $data[$key] : null;

        return $this->view->render('layout', [
            'title'   => 'Webmention.io',
            'nav'     => null,
            'content' => $this->view->partial('status', [
                'error'       => $string('error'),
                'description' => $string('error_description'),
                'status'      => $string('status'),
                'source'      => $string('source'),
                'target'      => $string('target'),
                'summary'     => $string('summary'),
                'location'    => $string('location'),
                'json'        => json_encode($data, self::FLAGS | JSON_PRETTY_PRINT) ?: '{}',
            ]),
        ]);
    }

    public static function encode(mixed $data): string
    {
        return json_encode($data, self::FLAGS) ?: '{}';
    }

    /** A JavaScript identifier path such as `cb`, `jQuery123_456` or `app.render`. */
    public static function validCallback(string $callback): bool
    {
        return strlen($callback) <= 128
            && preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*(\.[A-Za-z_$][A-Za-z0-9_$]*)*$/', $callback) === 1;
    }

    /** "invalid_target" → "Invalid Target", as the old status page showed it. */
    public static function titleize(string $value): string
    {
        return implode(' ', array_map(static fn (string $w): string => ucfirst($w), explode('_', $value)));
    }
}
