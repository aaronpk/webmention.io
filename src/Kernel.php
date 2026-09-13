<?php

declare(strict_types=1);

namespace Webmention;

use Throwable;
use Webmention\Http\HttpException;
use Webmention\Http\Request;
use Webmention\Http\Response;
use Webmention\Logging\Log;
use Webmention\View\Template;

/**
 * Turns a Request into a Response: match a route, resolve the controller,
 * invoke it, and convert anything thrown into an error page.
 */
final class Kernel
{
    /**
     * The policy for HTML pages. Controllers that need something different
     * (the embeddable mentions feed, the sign-in form) set their own header,
     * and it is left alone.
     */
    public static function csp(string $formAction = "'self'"): string
    {
        return "default-src 'none'; script-src 'self'; style-src 'self'; img-src * data:; "
            . "form-action $formAction; frame-ancestors 'none'; base-uri 'none'";
    }

    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly Log $log,
        private readonly bool $debug = false,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $match = $this->router->match($request->method, $request->path);

            [$class, $method] = $match->handler;

            /** @var Response $response */
            $response = $this->container->get($class)->{$method}($request, $match->params);

            return $this->withSecurityHeaders($response);
        } catch (HttpException $e) {
            $response = $this->errorPage($e->status, $e->getMessage());
            foreach ($e->headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }

            return $this->withSecurityHeaders($response);
        } catch (Throwable $e) {
            $this->log->exception($e, $request->method . ' ' . $request->path);

            $message = $this->debug
                ? sprintf('%s: %s (%s:%d)', $e::class, $e->getMessage(), $e->getFile(), $e->getLine())
                : 'Something went wrong.';

            return $this->withSecurityHeaders($this->errorPage(500, $message));
        }
    }

    private function errorPage(int $status, string $message): Response
    {
        try {
            $view = $this->container->get(Template::class);

            return Response::html($view->render('layout', [
                'title'   => 'Error',
                'nav'     => null,
                'content' => $view->partial('error', ['status' => $status, 'message' => $message]),
            ]), $status);
        } catch (Throwable $e) {
            $this->log->exception($e, 'rendering error page');

            return Response::text($status . ' ' . $message, $status);
        }
    }

    private function withSecurityHeaders(Response $response): Response
    {
        $response = $response->withHeader('x-content-type-options', 'nosniff');

        if (str_starts_with((string) $response->header('content-type'), 'text/html')) {
            if (!$response->hasHeader('content-security-policy')) {
                $response = $response->withHeader('content-security-policy', self::csp());
            }
            if (!$response->hasHeader('referrer-policy')) {
                $response = $response->withHeader('referrer-policy', 'strict-origin-when-cross-origin');
            }
        }

        return $response;
    }
}
