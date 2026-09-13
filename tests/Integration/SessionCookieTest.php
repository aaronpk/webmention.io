<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use Webmention\Http\Session;
use Webmention\Tests\Support\IntegrationTestCase;
use Webmention\Tests\Support\Subprocess;

/**
 * Native sessions only work in a real web server, so this starts one.
 */
final class SessionCookieTest extends IntegrationTestCase
{
    private ?Subprocess $server = null;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $port         = Subprocess::freePort();
        $this->base   = "http://127.0.0.1:$port";
        $this->server = new Subprocess(['php', '-S', "127.0.0.1:$port", '-t', 'public', 'public/index.php']);

        Subprocess::waitFor(
            fn (): ?bool => @file_get_contents($this->base . '/robots.txt') !== false ? true : null,
            10,
            'the dev server to start',
        );
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testSigningInStartsAHardenedSessionStoredInRedis(): void
    {
        // Discovery fails for a .invalid host, but the session has started by then.
        [$status, $headers] = $this->get('/auth/start?me=' . rawurlencode('https://user.invalid/'));

        self::assertSame(400, $status);

        $cookie = self::setCookie($headers);
        self::assertNotNull($cookie, 'No session cookie was set');
        self::assertStringStartsWith(Session::COOKIE . '=', $cookie);
        self::assertStringContainsStringIgnoringCase('; HttpOnly', $cookie);
        self::assertStringContainsStringIgnoringCase('; SameSite=Lax', $cookie);
        self::assertStringNotContainsStringIgnoringCase('; secure', $cookie);
        self::assertStringContainsString('; path=/', $cookie);

        $id = substr(explode(';', $cookie)[0], strlen(Session::COOKIE) + 1);
        self::assertSame(1, $this->redis->exists('webmention:session:' . $id));
    }

    public function testApiAndWebmentionRequestsDoNotCreateSessions(): void
    {
        foreach (['/api/count?target=https://example.com/', '/api/mentions.jf2?target=https://example.com/', '/', '/example.com/webmention'] as $path) {
            [, $headers] = $this->get($path);
            self::assertNull(self::setCookie($headers), "$path set a cookie");
        }
    }

    public function testPagesBehindSignInRedirectHome(): void
    {
        [$status, $headers] = $this->get('/dashboard');

        self::assertSame(302, $status);
        self::assertContains('location: /', array_map('strtolower', $headers));
    }

    /** @return array{int, list<string>} */
    private function get(string $path): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
        @file_get_contents($this->base . $path, false, $context);

        $headers = $http_response_header ?? [];
        preg_match('/\s(\d{3})/', $headers[0] ?? '', $m);

        return [(int) ($m[1] ?? 0), $headers];
    }

    /** @param list<string> $headers */
    private static function setCookie(array $headers): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Set-Cookie: ' . Session::COOKIE . '=') === 0) {
                return substr($header, strlen('Set-Cookie: '));
            }
        }

        return null;
    }
}
