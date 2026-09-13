<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use IndieAuth\Client;
use Webmention\Storage\AccountRepository;
use Webmention\Tests\Support\IntegrationTestCase;
use Webmention\Webmention\HttpClient;

/**
 * Signing in against a fake IndieAuth authorization server.
 *
 * indieauth/client caches fetched pages in static properties, so each test
 * signs in as a different host.
 */
final class AuthFlowTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Client::$http = $this->service(HttpClient::class)->http();
        Client::setMetadata('about:blank', 'null');
    }

    protected function tearDown(): void
    {
        Client::$http = null;
    }

    public function testNewUserIsCreatedAndSentToSiteSetup(): void
    {
        $this->fakeProfile('alice.example');

        $location = $this->startSignIn('https://alice.example');
        self::assertStringStartsWith('https://auth.example/auth?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        self::assertSame('https://alice.example/', $params['me']);
        self::assertSame('https://webmention.io/id', $params['client_id']);
        self::assertSame('https://webmention.io/auth/callback', $params['redirect_uri']);
        self::assertSame('S256', $params['code_challenge_method']);

        $this->http->respond('POST', 'https://auth.example/auth', 200, '{"me":"https://alice.example/"}', ['Content-Type' => 'application/json']);

        $response = $this->request('GET', '/auth/callback', ['code' => 'c0de', 'state' => $params['state'], 'iss' => 'https://auth.example/']);

        self::assertSame(302, $response->status, $response->body);
        self::assertSame('/settings/sites', $response->header('location'));

        $account = $this->service(AccountRepository::class)->findByDomain('alice.example');
        self::assertNotNull($account);
        self::assertSame('alice.example', $account->username);
        self::assertSame($account->id, $_SESSION['user_id']);

        $exchange = $this->http->posts('https://auth.example/auth')[0];
        parse_str((string) $exchange['body'], $body);
        self::assertSame('c0de', $body['code']);
        self::assertArrayHasKey('code_verifier', $body);
    }

    public function testReturningUserWithMentionsGoesToTheDashboard(): void
    {
        $account = $this->createAccount('bob.example');
        $this->createLink($this->createSite($account, 'bob.example'), 'https://bob.example/post', 'https://carol.example/reply');
        $this->fakeProfile('bob.example');

        $location = $this->startSignIn('https://bob.example');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        $this->http->respond('POST', 'https://auth.example/auth', 200, '{"me":"https://bob.example/"}', ['Content-Type' => 'application/json']);

        $response = $this->request('GET', '/auth/callback', ['code' => 'x', 'state' => $params['state']]);

        self::assertSame('/dashboard', $response->header('location'));
        self::assertSame($account->id, $_SESSION['user_id']);
        self::assertCount(1, $this->db->all('SELECT id FROM accounts WHERE domain = ?', ['bob.example']));
    }

    public function testStateMismatchIsRejected(): void
    {
        $this->fakeProfile('dave.example');
        $this->startSignIn('https://dave.example/');

        $response = $this->request('GET', '/auth/callback', ['code' => 'x', 'state' => 'forged']);

        self::assertSame(400, $response->status);
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testProfileUrlWithAQueryStringIsRefused(): void
    {
        $this->fakeProfile('erin.example');
        $location = $this->startSignIn('https://erin.example/');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        $this->http->respond('POST', 'https://auth.example/auth', 200, '{"me":"https://erin.example/?user=1"}', ['Content-Type' => 'application/json']);

        $response = $this->request('GET', '/auth/callback', ['code' => 'x', 'state' => $params['state']]);

        // The returned URL differs from the entered one, and doesn't declare the same server.
        self::assertSame(400, $response->status);
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testSiteWithoutAnAuthorizationEndpointGetsAnError(): void
    {
        $this->http->respond('GET', 'https://nobody.example/', 200, '<html><body>No IndieAuth here</body></html>', ['Content-Type' => 'text/html']);

        $response = $this->request('GET', '/auth/start', ['me' => 'https://nobody.example/']);

        self::assertSame(400, $response->status);
        self::assertStringContainsString('Could not find your authorization endpoint', $response->body);
    }

    public function testSignInFormMayRedirectToAnyAuthorizationServer(): void
    {
        $home = (string) $this->request('GET', '/')->header('content-security-policy');
        self::assertStringContainsString("form-action 'self' https: http:;", $home);

        // Every other page's forms stay on this site.
        $this->signIn($this->createAccount('grace.example'));
        $settings = (string) $this->request('GET', '/settings')->header('content-security-policy');
        self::assertStringContainsString("form-action 'self';", $settings);
    }

    public function testEmptySignInGoesHome(): void
    {
        self::assertSame('/', $this->request('GET', '/auth/start', ['me' => ' '])->header('location'));
    }

    public function testLogout(): void
    {
        $this->signIn($this->createAccount('frank.example'));

        $response = $this->request('GET', '/logout');

        self::assertSame('/', $response->header('location'));
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    private function fakeProfile(string $host): void
    {
        $this->http->respond(
            'GET',
            "https://$host/",
            200,
            '<html><head><link rel="authorization_endpoint" href="https://auth.example/auth"></head><body>Hi</body></html>',
            ['Content-Type' => 'text/html'],
        );
    }

    private function startSignIn(string $me): string
    {
        $response = $this->request('GET', '/auth/start', ['me' => $me]);
        self::assertSame(302, $response->status, $response->body);

        return (string) $response->header('location');
    }
}
