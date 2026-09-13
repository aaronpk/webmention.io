<?php

declare(strict_types=1);

namespace Webmention;

use Webmention\Http\HttpException;

/**
 * A small pattern-matching router.
 *
 * Patterns may contain {name} placeholders, which match a single path segment.
 * Handlers are [class-string, method] pairs resolved through the container at
 * dispatch time, so controllers are only constructed when actually routed to.
 */
final class Router
{
    /** @var list<array{methods: list<string>, pattern: string, regex: string, params: list<string>, handler: array{0: string, 1: string}}> */
    private array $routes = [];

    /** @param array{0: class-string, 1: string} $handler */
    public function get(string $pattern, array $handler): void
    {
        $this->add(['GET', 'HEAD'], $pattern, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function post(string $pattern, array $handler): void
    {
        $this->add(['POST'], $pattern, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function any(string $pattern, array $handler): void
    {
        $this->add(['GET', 'HEAD', 'POST'], $pattern, $handler);
    }

    /**
     * @param list<string>                  $methods
     * @param array{0: class-string, 1: string} $handler
     */
    public function add(array $methods, string $pattern, array $handler): void
    {
        // Split into literal runs and {placeholder} tokens, so literals can be
        // escaped (dots in /.well-known must stay literal) while placeholders
        // become capture groups.
        $tokens = preg_split(
            '/(\{[a-z_][a-z0-9_]*\})/i',
            $pattern,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        ) ?: [];

        $params = [];
        $regex  = '';
        foreach ($tokens as $token) {
            if (preg_match('/^\{([a-z_][a-z0-9_]*)\}$/i', $token, $m) === 1) {
                $params[] = $m[1];
                $regex .= '([^\/]+)';
            } else {
                $regex .= preg_quote($token, '#');
            }
        }

        $this->routes[] = [
            'methods' => $methods,
            'pattern' => $pattern,
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    /**
     * Every registered route.
     *
     * Exposed so the suite can audit the whole surface at once — that every
     * handler resolves, and that every state-changing route enforces CSRF —
     * rather than relying on each route remembering to test itself.
     *
     * @return list<array{methods: list<string>, pattern: string, handler: array{0: class-string, 1: string}}>
     */
    public function routes(): array
    {
        return array_map(
            static fn (array $route): array => [
                'methods' => $route['methods'],
                'pattern' => $route['pattern'],
                'handler' => $route['handler'],
            ],
            $this->routes,
        );
    }

    /**
     * @throws HttpException 404 when no pattern matches, 405 when one matches
     *                       but not for this method.
     */
    public function match(string $method, string $path): RouteMatch
    {
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m) !== 1) {
                continue;
            }

            if (!in_array($method, $route['methods'], true)) {
                foreach ($route['methods'] as $candidate) {
                    $allowed[$candidate] = true;
                }
                continue;
            }

            array_shift($m);
            $params = [];
            foreach ($route['params'] as $i => $name) {
                $params[$name] = rawurldecode($m[$i] ?? '');
            }

            return new RouteMatch($route['handler'], $params);
        }

        if ($allowed !== []) {
            throw HttpException::methodNotAllowed(array_keys($allowed));
        }

        throw HttpException::notFound();
    }
}
