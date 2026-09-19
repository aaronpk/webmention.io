<?php

declare(strict_types=1);

namespace Webmention\View;

use RuntimeException;
use Throwable;

/**
 * Plain PHP templates with escape-by-default semantics.
 *
 * Values passed to render() are HTML-escaped recursively before the template
 * sees them, so `<?= $client_name ?>` is safe without ceremony. To emit markup,
 * wrap it in Raw.
 *
 * Escaping here is for HTML text and quoted attribute contexts. A value
 * interpolated into a URL still needs rawurlencode, and one interpolated into
 * inline JavaScript still needs json_encode — escaping is not a substitute for
 * getting the context right.
 */
final class Template
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @param array<string, mixed> $vars */
    public function render(string $name, array $vars = []): string
    {
        if (preg_match('/^[a-z0-9_\-\/]+$/i', $name) !== 1 || str_contains($name, '..')) {
            throw new RuntimeException(sprintf('Unsafe template name "%s".', $name));
        }

        $file = $this->directory . '/' . $name . '.php';
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Template "%s" not found.', $name));
        }

        /** @var array<string, mixed> $escaped */
        $escaped = $this->escape($vars);

        // Give templates a renderer so they can compose partials.
        $escaped['view'] = $this;

        ob_start();
        try {
            (static function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                require $__file;
            })($file, $escaped);
        } catch (Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Renders a partial for inclusion in another template. Values are escaped
     * exactly as in render(); the Raw wrapper applies only to the resulting
     * markup so the including template does not double-escape it.
     *
     * @param array<string, mixed> $vars
     */
    public function partial(string $name, array $vars = []): Raw
    {
        return new Raw($this->render($name, $vars));
    }

    /**
     * A URL for a file under public/ with its modification time as a query
     * string, so a changed stylesheet or script is fetched afresh instead of
     * served from a browser's cache.
     */
    public function asset(string $path): string
    {
        $file  = dirname($this->directory) . '/public' . $path;
        $mtime = is_file($file) ? filemtime($file) : false;

        return $mtime === false ? $path : $path . '?v=' . $mtime;
    }

    private function escape(mixed $value): mixed
    {
        if ($value instanceof Raw) {
            return $value->html;
        }

        if (is_string($value)) {
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }

        if (is_array($value)) {
            return array_map($this->escape(...), $value);
        }

        // int, float, bool, null and objects (e.g. Template) pass through.
        return $value;
    }
}
