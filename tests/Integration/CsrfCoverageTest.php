<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Bootstrap;
use Webmention\Tests\Support\IntegrationTestCase;

/**
 * Every route that changes state must refuse a request without the session's
 * CSRF token. Checked from the route table, so a new POST route that forgets
 * checkCsrf() fails here rather than shipping.
 */
final class CsrfCoverageTest extends IntegrationTestCase
{
    /**
     * Routes anyone may POST to: the webmention endpoints are the protocol,
     * and starting a sign-in has no session yet (it checks the request's
     * origin instead; see AuthFlowTest).
     */
    private const OPEN = ['/d/{domain}/webmention', '/{username}/webmention', '/auth/start'];

    public function testEveryPostRouteRequiresTheCsrfToken(): void
    {
        $this->signIn($this->createAccount('alice.example'));

        $checked = 0;
        foreach (Bootstrap::router()->routes() as $route) {
            if (!in_array('POST', $route['methods'], true) || in_array($route['pattern'], self::OPEN, true)) {
                continue;
            }
            self::assertStringNotContainsString('{', $route['pattern'], 'Fill in placeholders for ' . $route['pattern']);

            self::assertSame(403, $this->request('POST', $route['pattern'])->status, $route['pattern'] . ' without a token');
            self::assertSame(403, $this->request('POST', $route['pattern'], post: ['csrf' => 'wrong'])->status, $route['pattern'] . ' with a wrong token');
            $checked++;
        }

        self::assertGreaterThanOrEqual(6, $checked);
    }

    public function testEveryHandlerExists(): void
    {
        foreach (Bootstrap::router()->routes() as $route) {
            [$class, $method] = $route['handler'];
            self::assertTrue(method_exists($class, $method), "$class::$method for {$route['pattern']}");
        }
    }
}
