<?php

declare(strict_types=1);

namespace Webmention\Tests\Integration;

use IndieAuth\Client;
use Webmention\Controllers\AuthController;
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
    /** What a browser sends when the form on this site is submitted. */
    private const SAME_ORIGIN = ['sec-fetch-site' => 'same-origin'];

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

        $response = $this->request('POST', '/auth/start', post: ['me' => 'https://nobody.example/'], headers: self::SAME_ORIGIN);

        self::assertSame(400, $response->status);
        self::assertStringContainsString('Could not find your authorization endpoint', $response->body);
    }

    public function testSignInCannotBeStartedFromAnotherSite(): void
    {
        $this->fakeProfile('mallory.example');

        // A form on another site, or a bare request with no browser headers at all.
        foreach ([['sec-fetch-site' => 'cross-site'], ['origin' => 'https://evil.example'], ['referer' => 'https://evil.example/page'], []] as $headers) {
            $response = $this->request('POST', '/auth/start', post: ['me' => 'https://mallory.example/'], headers: $headers);
            self::assertSame(403, $response->status, json_encode($headers));
        }
        self::assertSame([], $this->http->requests, 'Nothing should have been fetched');

        // Older browsers send Origin or Referer instead of Sec-Fetch-Site.
        self::assertSame(302, $this->request('POST', '/auth/start', post: ['me' => 'https://mallory.example/'], headers: ['origin' => 'https://webmention.io'])->status);
        self::assertSame(302, $this->request('POST', '/auth/start', post: ['me' => 'https://mallory.example/'], headers: ['referer' => 'https://webmention.io/'])->status);
    }

    public function testOldSignInLinksLandOnTheHomePage(): void
    {
        $response = $this->request('GET', '/auth/start', ['me' => 'https://alice.example/']);

        self::assertSame(302, $response->status);
        self::assertSame('/?me=' . rawurlencode('https://alice.example/'), $response->header('location'));
        self::assertSame([], $this->http->requests);

        self::assertStringContainsString('value="https://alice.example/"', $this->request('GET', '/', ['me' => 'https://alice.example/'])->body);
    }

    public function testProfileUrlsMustBeHttpsWithoutPortOrUserinfo(): void
    {
        foreach (['http://alice.example/', 'https://alice.example:8443/', 'https://user@alice.example/', 'https://alice.example/#me', 'not a url', 'https://localhost/'] as $me) {
            $response = $this->request('POST', '/auth/start', post: ['me' => $me], headers: self::SAME_ORIGIN);
            self::assertSame(400, $response->status, $me);
        }
        self::assertSame([], $this->http->requests, 'Nothing should have been fetched');
    }

    public function testProfileUrlsAreNormalisedBeforeDiscovery(): void
    {
        self::assertSame('https://alice.example/', AuthController::normalizeMe('alice.example'));
        self::assertSame('https://alice.example/', AuthController::normalizeMe(' HTTPS://Alice.Example/ '));
        self::assertSame('https://alice.example/~me/', AuthController::normalizeMe('https://alice.example/~me/'));
        if (function_exists('idn_to_ascii')) {
            self::assertSame('https://xn--80ak6aa92e.example/', AuthController::normalizeMe('https://аррӏе.example/'));
        }
        self::assertIsArray(AuthController::normalizeMe('http://alice.example/'));
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
        self::assertSame('/', $this->request('POST', '/auth/start', post: ['me' => ' '], headers: self::SAME_ORIGIN)->header('location'));
        self::assertSame('/', $this->request('GET', '/auth/start', ['me' => ' '])->header('location'));
    }

    public function testTooManySignInAttemptsAreRefused(): void
    {
        $this->fakeProfile('heidi.example');

        for ($i = 0; $i < 10; $i++) {
            self::assertSame(302, $this->request('POST', '/auth/start', post: ['me' => 'https://heidi.example/'], headers: self::SAME_ORIGIN)->status);
        }

        self::assertSame(429, $this->request('POST', '/auth/start', post: ['me' => 'https://heidi.example/'], headers: self::SAME_ORIGIN)->status);
    }

    public function testLogoutIsAPostWithTheCsrfToken(): void
    {
        $csrf = $this->signIn($this->createAccount('frank.example'));

        // A link or a forged form can't sign the user out.
        $this->request('GET', '/logout');
        self::assertArrayHasKey('user_id', $_SESSION);
        self::assertSame(403, $this->request('POST', '/logout')->status);
        self::assertArrayHasKey('user_id', $_SESSION);

        $response = $this->request('POST', '/logout', post: ['csrf' => $csrf]);

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
        $response = $this->request('POST', '/auth/start', post: ['me' => $me], headers: self::SAME_ORIGIN);
        self::assertSame(302, $response->status, $response->body);

        return (string) $response->header('location');
    }
}
