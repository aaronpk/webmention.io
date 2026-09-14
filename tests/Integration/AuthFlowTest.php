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

    public function testSiteWithOnlyRelMeLinksIsSignedInThroughIndielogin(): void
    {
        $this->http->respond('GET', 'https://nobody.example/', 200, '<html><head><link rel="me" href="https://github.com/nobody"></head><body>No IndieAuth here</body></html>', ['Content-Type' => 'text/html']);

        $location = $this->startSignIn('https://nobody.example/');
        self::assertStringStartsWith('https://indielogin.com/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        self::assertSame('https://nobody.example/', $params['me']);
        self::assertSame('https://webmention.io/', $params['client_id'], 'indielogin wants the app home page, on the same host as the redirect');
        self::assertSame('https://webmention.io/auth/callback', $params['redirect_uri']);
        self::assertSame('S256', $params['code_challenge_method']);
        self::assertNotEmpty($params['state']);

        $this->http->respond('POST', 'https://indielogin.com/token', 200, '{"me":"https://nobody.example/"}', ['Content-Type' => 'application/json']);
        $response = $this->request('GET', '/auth/callback', ['code' => 'c0de', 'state' => $params['state'], 'iss' => 'https://indielogin.com/']);

        self::assertSame(302, $response->status, $response->body);
        self::assertSame('/settings/sites', $response->header('location'));
        $account = $this->service(AccountRepository::class)->findByDomain('nobody.example');
        self::assertNotNull($account);
        self::assertSame($account->id, $_SESSION['user_id']);
        self::assertArrayNotHasKey('indielogin_state', $_SESSION);

        $exchange = $this->http->posts('https://indielogin.com/token')[0];
        parse_str((string) $exchange['body'], $body);
        self::assertSame('c0de', $body['code']);
        self::assertSame('https://webmention.io/', $body['client_id']);
        self::assertSame('https://webmention.io/auth/callback', $body['redirect_uri']);
        self::assertSame($params['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', $body['code_verifier'], true)), '+/', '-_'), '='), 'PKCE verifier matches the challenge');
        self::assertContains('Accept: application/json', $exchange['headers']);
    }

    public function testIndieloginCallbackIsCheckedAndItsErrorsShown(): void
    {
        // One host per start: the library caches fetched pages for the whole process.
        foreach (['lonely1', 'lonely2', 'lonely3'] as $host) {
            $this->http->respond('GET', "https://$host.example/", 200, '<html><body>rel=me only</body></html>', ['Content-Type' => 'text/html']);
        }

        // Forged state.
        $this->startSignIn('https://lonely1.example/');
        $response = $this->request('GET', '/auth/callback', ['code' => 'x', 'state' => 'forged']);
        self::assertSame(400, $response->status);
        self::assertArrayNotHasKey('user_id', $_SESSION);
        self::assertSame([], $this->http->posts('https://indielogin.com/token'), 'nothing is redeemed');

        // Wrong issuer.
        parse_str((string) parse_url($this->startSignIn('https://lonely2.example/'), PHP_URL_QUERY), $params);
        $response = $this->request('GET', '/auth/callback', ['code' => 'x', 'state' => $params['state'], 'iss' => 'https://evil.example/']);
        self::assertSame(400, $response->status);
        self::assertStringContainsString('unexpected server', $response->body);

        // indielogin declined.
        parse_str((string) parse_url($this->startSignIn('https://lonely3.example/'), PHP_URL_QUERY), $params);
        $this->http->respond('POST', 'https://indielogin.com/token', 400, '{"error":"invalid_request","error_description":"The code provided was not valid"}', ['Content-Type' => 'application/json']);
        $response = $this->request('GET', '/auth/callback', ['code' => 'bad', 'state' => $params['state']]);
        self::assertSame(400, $response->status);
        self::assertStringContainsString('The code provided was not valid', $response->body);
        self::assertArrayNotHasKey('user_id', $_SESSION);

        // A callback with the state used once cannot be replayed.
        $response = $this->request('GET', '/auth/callback', ['code' => 'bad', 'state' => $params['state']]);
        self::assertSame(400, $response->status);
    }

    public function testAnUnreachableSiteIsAnErrorNotAHandOff(): void
    {
        $this->http->respond('GET', 'https://down.example/', 0, '', [], 'Could not resolve host: down.example');
        $response = $this->request('POST', '/auth/start', post: ['me' => 'https://down.example/'], headers: self::SAME_ORIGIN);
        self::assertSame(400, $response->status);
        self::assertStringContainsString('could not be fetched', $response->body);

        $this->http->respond('GET', 'https://gone.example/', 404, '<html><body>Not here</body></html>', ['Content-Type' => 'text/html']);
        $response = $this->request('POST', '/auth/start', post: ['me' => 'https://gone.example/'], headers: self::SAME_ORIGIN);
        self::assertSame(400, $response->status);
        self::assertStringContainsString('(HTTP 404)', $response->body);
    }

    public function testIndieloginFallbackCanBeTurnedOff(): void
    {
        // A host no other test fetched: the library caches pages per process.
        $this->http->respond('GET', 'https://alone.example/', 200, '<html><body>No IndieAuth here</body></html>', ['Content-Type' => 'text/html']);
        putenv('INDIELOGIN_URL=off');
        try {
            $response = $this->request('POST', '/auth/start', post: ['me' => 'https://alone.example/'], headers: self::SAME_ORIGIN);
        } finally {
            putenv('INDIELOGIN_URL');
        }

        self::assertSame(400, $response->status);
        self::assertStringContainsString('Could not find your authorization endpoint', $response->body);
    }

    public function testLegacyEndpointAnsweringFormEncodedSignsIn(): void
    {
        // Only rel="authorization_endpoint", no metadata; the code is redeemed
        // at that endpoint and answered the pre-2020 way.
        $this->fakeProfile('carol.example');
        $location = $this->startSignIn('https://carol.example/');
        self::assertStringStartsWith('https://auth.example/auth?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);

        $this->http->respond('POST', 'https://auth.example/auth', 200, 'me=' . rawurlencode('https://carol.example/'), ['Content-Type' => 'application/x-www-form-urlencoded']);
        $response = $this->request('GET', '/auth/callback', ['code' => 'legacy', 'state' => $params['state']]);

        self::assertSame(302, $response->status, $response->body);
        self::assertSame('/settings/sites', $response->header('location'));
        self::assertNotNull($this->service(AccountRepository::class)->findByDomain('carol.example'));
    }

    public function testReturnedProfileUrlWithoutTrailingSlashIsTheSameAccount(): void
    {
        $this->fakeProfile('ivan.example');
        $this->http->respond('GET', 'https://ivan.example', 200, '<html><head><link rel="authorization_endpoint" href="https://auth.example/auth"></head></html>', ['Content-Type' => 'text/html']);
        $existing = $this->createAccount('ivan.example');

        parse_str((string) parse_url($this->startSignIn('https://ivan.example/'), PHP_URL_QUERY), $params);
        $this->http->respond('POST', 'https://auth.example/auth', 200, '{"me":"https://ivan.example"}', ['Content-Type' => 'application/json']);
        $response = $this->request('GET', '/auth/callback', ['code' => 'x', 'state' => $params['state']]);

        self::assertSame(302, $response->status, $response->body);
        self::assertSame($existing->id, $_SESSION['user_id']);
    }

    public function testFailuresAreLogged(): void
    {
        $log = sys_get_temp_dir() . '/webmention-test.log';
        @unlink($log);
        $this->fakeProfile('judy.example');
        $this->startSignIn('https://judy.example/');
        $this->request('GET', '/auth/callback', ['code' => 'x', 'state' => 'forged']);

        $written = (string) @file_get_contents($log);
        self::assertStringContainsString('Sign-in failed (indieauth) for https://judy.example/', $written);
        self::assertStringNotContainsString('forged', $written, 'state values stay out of the log');
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
