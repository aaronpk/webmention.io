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

    public function testAnUnreadableMetadataDocumentIsNamedRatherThanBlamedOnTheIssuer(): void
    {
        $log = sys_get_temp_dir() . '/webmention-test.log';
        @unlink($log);
        $this->http->respond('GET', 'https://kim.example/', 200, '<html><head><link rel="indieauth-metadata" href="https://ids.example/s/kim/metadata"></head><body>Hi</body></html>', ['Content-Type' => 'text/html']);

        // The server could not be fetched at all (what a blocked address looks like).
        $this->http->respond('GET', 'https://ids.example/s/kim/metadata', 0, '', [], 'blocked');
        $response = $this->request('POST', '/auth/start', post: ['me' => 'https://kim.example/'], headers: self::SAME_ORIGIN);
        self::assertSame(400, $response->status);
        self::assertStringContainsString('metadata at https://ids.example/s/kim/metadata could not be read (Simulated blocked).', $response->body);
        self::assertStringNotContainsString('No issuer found', $response->body);
        self::assertStringContainsString('Sign-in failed (indieauth) for https://kim.example/: invalid_issuer: Your IndieAuth server\'s metadata at https://ids.example/s/kim/metadata could not be read (Simulated blocked).', (string) @file_get_contents($log));

        // A server error.
        self::resetIndieAuthClient();
        $this->http->respond('GET', 'https://ids.example/s/kim/metadata', 503, 'down', ['Content-Type' => 'text/html']);
        $response = $this->request('POST', '/auth/start', post: ['me' => 'https://kim.example/'], headers: self::SAME_ORIGIN);
        self::assertStringContainsString('could not be read (HTTP 503).', $response->body);

        // Reachable, but not JSON.
        self::resetIndieAuthClient();
        $this->http->respond('GET', 'https://ids.example/s/kim/metadata', 200, '<html>not json</html>', ['Content-Type' => 'text/html']);
        $response = $this->request('POST', '/auth/start', post: ['me' => 'https://kim.example/'], headers: self::SAME_ORIGIN);
        self::assertStringContainsString('could not be read (it is not a JSON document with an issuer).', $response->body);

        // And when it is fine, sign-in proceeds to the authorization endpoint it names.
        self::resetIndieAuthClient();
        $this->http->respond('GET', 'https://ids.example/s/kim/metadata', 200, json_encode(['issuer' => 'https://ids.example/s/kim/', 'authorization_endpoint' => 'https://ids.example/s/kim/auth', 'token_endpoint' => 'https://ids.example/token']), ['Content-Type' => 'application/json']);
        $response = $this->request('POST', '/auth/start', post: ['me' => 'https://kim.example/'], headers: self::SAME_ORIGIN);
        self::assertSame(302, $response->status);
        self::assertStringStartsWith('https://ids.example/s/kim/auth?', (string) $response->header('location'));
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
        self::assertStringContainsString('Your website does not link to an IndieAuth server', $response->body);
        self::assertStringContainsString('<li class="stage ok">', $response->body);
        self::assertStringContainsString('no rel=&quot;indieauth-metadata&quot; and no rel=&quot;authorization_endpoint&quot; link', $response->body);
        self::assertStringContainsString('<li class="stage failed">', $response->body);
        self::assertStringContainsString('Add &lt;link rel=&quot;indieauth-metadata&quot;', $response->body, 'the hint for that stage');
    }

    /** Between sign-ins in one test: the library caches discovery per process, and production sees one sign-in per request. */
    private function fresh(): void
    {
        self::resetIndieAuthClient();
        Client::setMetadata('about:blank', 'null');
    }

    /** @return list<string> The state of each stage on the page, in order. */
    private static function stageStates(string $body): array
    {
        preg_match_all('/<li class="stage (ok|failed|skipped)">/', $body, $m);

        return $m[1];
    }

    public function testDiscoveryFailuresNameTheStageAndTheEvidence(): void
    {
        // The site cannot be fetched at all.
        $this->http->respond('GET', 'https://nx.example/', 0, '', [], 'dns_error');
        $body = $this->request('POST', '/auth/start', post: ['me' => 'https://nx.example/'], headers: self::SAME_ORIGIN)->body;
        self::assertSame(['failed', 'skipped', 'skipped', 'skipped'], self::stageStates($body));
        self::assertStringContainsString('Your website could not be fetched (Simulated dns_error)', $body, 'the transport error, not HTTP 0');
        self::assertStringContainsString('Could not fetch https://nx.example/: Simulated dns_error.', $body);
        self::assertStringContainsString('<dt>Request</dt><dd><code>GET https://nx.example/</code></dd>', $body);

        // The site answers 404.
        $this->fresh();
        $this->http->respond('GET', 'https://gone.example/', 404, '<html>nope</html>', ['Content-Type' => 'text/html']);
        $body = $this->request('POST', '/auth/start', post: ['me' => 'https://gone.example/'], headers: self::SAME_ORIGIN)->body;
        self::assertSame(['failed', 'skipped', 'skipped', 'skipped'], self::stageStates($body));
        self::assertStringContainsString('Could not fetch https://gone.example/: HTTP 404.', $body);

        // Metadata link present but the document is a 500.
        $this->fresh();
        $this->http->respond('GET', 'https://m500.example/', 200, '<html><head><link rel="indieauth-metadata" href="https://ids.example/m500/metadata"></head></html>', ['Content-Type' => 'text/html']);
        $this->http->respond('GET', 'https://ids.example/m500/metadata', 500, 'down', ['Content-Type' => 'text/plain']);
        $body = $this->request('POST', '/auth/start', post: ['me' => 'https://m500.example/'], headers: self::SAME_ORIGIN)->body;
        self::assertSame(['ok', 'ok', 'failed', 'skipped'], self::stageStates($body));
        self::assertStringContainsString('<dt>rel=&quot;indieauth-metadata&quot;</dt><dd><code>https://ids.example/m500/metadata</code></dd>', $body);
        self::assertStringContainsString('Could not fetch https://ids.example/m500/metadata: HTTP 500.', $body);
        self::assertStringContainsString('The metadata document must be JSON', $body);

        // Not JSON.
        $this->fresh();
        $this->http->respond('GET', 'https://mhtml.example/', 200, '<html><head><link rel="indieauth-metadata" href="https://ids.example/mhtml/metadata"></head></html>', ['Content-Type' => 'text/html']);
        $this->http->respond('GET', 'https://ids.example/mhtml/metadata', 200, '<html>hi</html>', ['Content-Type' => 'text/html']);
        $body = $this->request('POST', '/auth/start', post: ['me' => 'https://mhtml.example/'], headers: self::SAME_ORIGIN)->body;
        self::assertSame(['ok', 'ok', 'failed', 'skipped'], self::stageStates($body));
        self::assertStringContainsString('https://ids.example/mhtml/metadata is not a JSON document.', $body);
        self::assertStringContainsString('<dt>Answer</dt><dd><code>HTTP 200, text/html, 15 bytes in ', $body);

        // JSON without an issuer.
        $this->fresh();
        $this->http->respond('GET', 'https://noiss.example/', 200, '<html><head><link rel="indieauth-metadata" href="https://ids.example/noiss/metadata"></head></html>', ['Content-Type' => 'text/html']);
        $this->http->respond('GET', 'https://ids.example/noiss/metadata', 200, '{"authorization_endpoint":"https://ids.example/auth"}', ['Content-Type' => 'application/json']);
        $body = $this->request('POST', '/auth/start', post: ['me' => 'https://noiss.example/'], headers: self::SAME_ORIGIN)->body;
        self::assertSame(['ok', 'ok', 'failed', 'skipped'], self::stageStates($body));
        self::assertStringContainsString('The metadata document has no issuer.', $body);
        self::assertStringContainsString('<dt>issuer</dt><dd><code>(missing)</code></dd>', $body);

        // Issuer with a query string.
        $this->fresh();
        $this->http->respond('GET', 'https://qiss.example/', 200, '<html><head><link rel="indieauth-metadata" href="https://ids.example/qiss/metadata"></head></html>', ['Content-Type' => 'text/html']);
        $this->http->respond('GET', 'https://ids.example/qiss/metadata', 200, '{"issuer":"https://ids.example/qiss/?x=1","authorization_endpoint":"https://ids.example/auth"}', ['Content-Type' => 'application/json']);
        $body = $this->request('POST', '/auth/start', post: ['me' => 'https://qiss.example/'], headers: self::SAME_ORIGIN)->body;
        self::assertSame(['ok', 'ok', 'failed', 'skipped'], self::stageStates($body));
        self::assertStringContainsString('The issuer is not valid: it must not have a query string or fragment.', $body);

        // Issuer that is not a prefix of the document URL.
        $this->fresh();
        $this->http->respond('GET', 'https://piss.example/', 200, '<html><head><link rel="indieauth-metadata" href="https://ids.example/piss/metadata"></head></html>', ['Content-Type' => 'text/html']);
        $this->http->respond('GET', 'https://ids.example/piss/metadata', 200, '{"issuer":"https://other.example/","authorization_endpoint":"https://ids.example/auth"}', ['Content-Type' => 'application/json']);
        $body = $this->request('POST', '/auth/start', post: ['me' => 'https://piss.example/'], headers: self::SAME_ORIGIN)->body;
        self::assertSame(['ok', 'ok', 'failed', 'skipped'], self::stageStates($body));
        self::assertStringContainsString("it must be a prefix of the metadata document&apos;s URL, https://ids.example/piss/metadata", $body);

        // The legacy link, no metadata: stage 3 reads as not needed, and the page carries the Try again link.
        $this->fresh();
        $this->fakeProfile('legacy.example');
        $this->http->respond('POST', 'https://auth.example/auth', 500, 'oops', ['Content-Type' => 'text/plain']);
        parse_str((string) parse_url($this->startSignIn('https://legacy.example/'), PHP_URL_QUERY), $params);
        $body = $this->request('GET', '/auth/callback', ['code' => 'secret-code', 'state' => $params['state']])->body;
        self::assertSame(['ok', 'ok', 'ok', 'ok', 'ok', 'failed', 'skipped'], self::stageStates($body));
        self::assertStringContainsString('<dt>rel=&quot;authorization_endpoint&quot;</dt><dd><code>https://auth.example/auth</code></dd>', $body);
        self::assertStringContainsString('Not needed: the endpoint was given directly.', $body);
        self::assertStringContainsString('href="/?me=https%3A%2F%2Flegacy.example%2F">Try again</a>', $body);
    }

    public function testReturnAndExchangeFailuresShowWhatCameBackWithoutSecrets(): void
    {
        $log = sys_get_temp_dir() . '/webmention-test.log';
        @unlink($log);

        // The server sent an error back instead of a code.
        $this->fakeProfile('denied.example');
        $this->startSignIn('https://denied.example/');
        $body = $this->request('GET', '/auth/callback', ['error' => 'access_denied', 'error_description' => 'You said no'])->body;
        self::assertSame(['ok', 'ok', 'ok', 'ok', 'failed', 'skipped', 'skipped'], self::stageStates($body));
        self::assertStringContainsString('sent back an error instead of a code: access_denied (You said no).', $body);

        // A forged state: the stage says so and the value never appears.
        $this->fresh();
        $this->fakeProfile('forged.example');
        $this->startSignIn('https://forged.example/');
        $body = $this->request('GET', '/auth/callback', ['code' => 'x', 'state' => 'forged-value-123'])->body;
        self::assertSame(['ok', 'ok', 'ok', 'ok', 'failed', 'skipped', 'skipped'], self::stageStates($body));
        self::assertStringContainsString('The state value that came back is not the one this sign-in started with.', $body);
        self::assertStringNotContainsString('forged-value-123', $body);

        // A wrong iss, with a metadata server: expected and received are shown.
        $this->fresh();
        $this->http->respond('GET', 'https://iss.example/', 200, '<html><head><link rel="indieauth-metadata" href="https://ids.example/iss/metadata"></head></html>', ['Content-Type' => 'text/html']);
        $this->http->respond('GET', 'https://ids.example/iss/metadata', 200, '{"issuer":"https://ids.example/iss/","authorization_endpoint":"https://ids.example/iss/auth","token_endpoint":"https://ids.example/token"}', ['Content-Type' => 'application/json']);
        parse_str((string) parse_url($this->startSignIn('https://iss.example/'), PHP_URL_QUERY), $params);
        $body = $this->request('GET', '/auth/callback', ['code' => 'x', 'state' => $params['state'], 'iss' => 'https://evil.example/'])->body;
        self::assertSame(['ok', 'ok', 'ok', 'ok', 'failed', 'skipped', 'skipped'], self::stageStates($body));
        self::assertStringContainsString('<dt>Expected iss</dt><dd><code>https://ids.example/iss/</code></dd>', $body);
        self::assertStringContainsString('<dt>Received iss</dt><dd><code>https://evil.example/</code></dd>', $body);
        self::assertStringContainsString('The metadata document was read and its issuer matches its URL.', $body);
        self::assertStringContainsString('<dt>token_endpoint</dt><dd><code>https://ids.example/token</code></dd>', $body);

        // The exchange answers 500 with HTML and a token that must not leak.
        $this->fresh();
        $this->fakeProfile('five.example');
        parse_str((string) parse_url($this->startSignIn('https://five.example/'), PHP_URL_QUERY), $params);
        $this->http->respond('POST', 'https://auth.example/auth', 500, '{"access_token":"secret123","error":"server_error","error_description":"boom"}', ['Content-Type' => 'application/json']);
        $body = $this->request('GET', '/auth/callback', ['code' => 'secret-code', 'state' => $params['state']])->body;
        self::assertSame(['ok', 'ok', 'ok', 'ok', 'ok', 'failed', 'skipped'], self::stageStates($body));
        self::assertStringContainsString('did not answer with your profile URL (HTTP 500 from https://auth.example/auth).', $body);
        self::assertStringContainsString('<dt>answer</dt><dd><code>HTTP 500, application/json</code></dd>', $body);
        self::assertStringContainsString('<dt>server error</dt><dd><code>server_error: boom</code></dd>', $body);
        self::assertStringContainsString('<dt>profile URL in the answer</dt><dd><code>none</code></dd>', $body);
        self::assertStringContainsString('&quot;access_token&quot;:&quot;…&quot;', $body);
        self::assertStringNotContainsString('secret123', $body);
        self::assertStringNotContainsString('secret-code', $body);
        self::assertStringContainsString('Your authorization server has to answer the code exchange with JSON', $body);

        $written = (string) @file_get_contents($log);
        self::assertStringContainsString('{site=ok discovery=ok metadata=ok authorize=ok return=ok exchange=failed profile=-; The code was sent', $written);
        self::assertStringContainsString('body "{"access_token":"…"', $written, 'the log excerpt is scrubbed too');
        self::assertStringNotContainsString('secret123', $written);
        self::assertStringNotContainsString('secret-code', $written);
        self::assertStringNotContainsString('forged-value-123', $written);
    }

    public function testProfileRefusalAndTheTokenEndpointRetryShowBothAttempts(): void
    {
        // The returned profile URL has a query string: everything up to the exchange is fine.
        $this->fakeProfile('query.example');
        parse_str((string) parse_url($this->startSignIn('https://query.example/'), PHP_URL_QUERY), $params);
        $this->http->respond('POST', 'https://auth.example/auth', 200, '{"me":"https://query.example/?user=1"}', ['Content-Type' => 'application/json']);
        $body = $this->request('GET', '/auth/callback', ['code' => 'x', 'state' => $params['state']])->body;
        self::assertSame(['ok', 'ok', 'ok', 'ok', 'ok', 'ok', 'failed'], self::stageStates($body), 'the discovery stages survive the round trip through the session');
        self::assertStringContainsString('<dt>Returned profile URL</dt><dd><code>https://query.example/?user=1</code></dd>', $body);
        self::assertStringContainsString("must be your own site without a query string", $body);

        // The authorization endpoint answers 303 and the token endpoint refuses: both attempts are listed.
        $this->fresh();
        $this->http->respond('GET', 'https://two.example/', 200, '<html><head><link rel="authorization_endpoint" href="https://old.example/authorization"><link rel="token_endpoint" href="https://old.example/token"></head></html>', ['Content-Type' => 'text/html']);
        parse_str((string) parse_url($this->startSignIn('https://two.example/'), PHP_URL_QUERY), $params);
        $this->http->respond('POST', 'https://old.example/authorization', 303, '', ['Location' => 'https://old.example/']);
        $this->http->respond('POST', 'https://old.example/token', 400, '{"error":"invalid_grant"}', ['Content-Type' => 'application/json']);
        $body = $this->request('GET', '/auth/callback', ['code' => 'x', 'state' => $params['state']])->body;
        self::assertSame(['ok', 'ok', 'ok', 'ok', 'ok', 'failed', 'skipped'], self::stageStates($body));
        self::assertStringContainsString('HTTP 303 from https://old.example/authorization; HTTP 400 from https://old.example/token', $body);
        self::assertStringContainsString('<dt>Attempt 1: endpoint</dt><dd><code>https://old.example/authorization</code></dd>', $body);
        self::assertStringContainsString('<dt>Attempt 2: server error</dt><dd><code>invalid_grant</code></dd>', $body);
    }

    public function testIndieloginFailuresReadTheSameWay(): void
    {
        $this->http->respond('GET', 'https://relme.example/', 200, '<html><head><link rel="me" href="https://github.com/relme"></head><body>Hi</body></html>', ['Content-Type' => 'text/html']);
        parse_str((string) parse_url($this->startSignIn('https://relme.example/'), PHP_URL_QUERY), $params);
        $this->http->respond('POST', 'https://indielogin.com/token', 400, '{"error":"invalid_request","error_description":"Code expired"}', ['Content-Type' => 'application/json']);

        $body = $this->request('GET', '/auth/callback', ['code' => 'x', 'state' => $params['state']])->body;

        self::assertSame(['ok', 'ok', 'skipped', 'ok', 'ok', 'failed', 'skipped'], self::stageStates($body));
        self::assertStringContainsString('so indielogin.com signs you in with your rel=&quot;me&quot; links', $body);
        self::assertStringContainsString('You were sent to https://indielogin.com.', $body);
        self::assertStringContainsString('https://indielogin.com did not confirm the sign-in: Code expired', $body);
        self::assertStringContainsString('<dt>endpoint</dt><dd><code>https://indielogin.com/token</code></dd>', $body);
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

    public function testCodeIsRedeemedAtTheTokenEndpointWhenTheAuthorizationEndpointWillNot(): void
    {
        // An older server pair: the authorization endpoint only issues codes
        // and answers a redemption with a redirect; the token endpoint redeems.
        $this->http->respond('GET', 'https://lee.example/', 200, '<html><head><link rel="authorization_endpoint" href="https://old.example/authorization"><link rel="token_endpoint" href="https://old.example/token"></head><body>Hi</body></html>', ['Content-Type' => 'text/html']);
        $location = $this->startSignIn('https://lee.example/');
        self::assertStringStartsWith('https://old.example/authorization?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);

        $this->http->respond('POST', 'https://old.example/authorization', 303, '', ['Location' => '/login?redirect=%2Fauthorization']);
        $this->http->respond('POST', 'https://old.example/token', 200, '{"access_token":"t0k3n","token_type":"Bearer","me":"https://lee.example/"}', ['Content-Type' => 'application/json']);

        $response = $this->request('GET', '/auth/callback', ['code' => 'c0de', 'state' => $params['state']]);

        self::assertSame(302, $response->status, $response->body);
        self::assertSame('/settings/sites', $response->header('location'));
        self::assertNotNull($this->service(AccountRepository::class)->findByDomain('lee.example'));

        self::assertCount(1, $this->http->posts('https://old.example/authorization'));
        $retry = $this->http->posts('https://old.example/token');
        self::assertCount(1, $retry);
        parse_str((string) $retry[0]['body'], $body);
        self::assertSame('c0de', $body['code']);
        self::assertSame('https://webmention.io/id', $body['client_id']);
        self::assertSame($params['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', $body['code_verifier'], true)), '+/', '-_'), '='), 'same PKCE verifier');
        self::assertSame(0, $this->db->value('SELECT COUNT(*) FROM accounts WHERE token = ?', ['t0k3n']), 'the access token is not kept anywhere');
    }

    public function testTokenEndpointCannotVouchForSomeoneElsesUrl(): void
    {
        $this->http->respond('GET', 'https://max.example/', 200, '<html><head><link rel="authorization_endpoint" href="https://old.example/authorization"><link rel="token_endpoint" href="https://old.example/token"></head></html>', ['Content-Type' => 'text/html']);
        $this->http->respond('GET', 'https://victim.example/', 200, '<html><head><link rel="authorization_endpoint" href="https://auth.example/auth"></head></html>', ['Content-Type' => 'text/html']);
        parse_str((string) parse_url($this->startSignIn('https://max.example/'), PHP_URL_QUERY), $params);

        $this->http->respond('POST', 'https://old.example/authorization', 303, '', ['Location' => '/login']);
        $this->http->respond('POST', 'https://old.example/token', 200, '{"me":"https://victim.example/"}', ['Content-Type' => 'application/json']);

        $response = $this->request('GET', '/auth/callback', ['code' => 'c0de', 'state' => $params['state']]);

        self::assertSame(400, $response->status);
        self::assertArrayNotHasKey('user_id', $_SESSION);
        self::assertNull($this->service(AccountRepository::class)->findByDomain('victim.example'));
    }

    public function testAnUnusableAnswerFromTheAuthorizationServerIsLoggedInDetail(): void
    {
        $log = sys_get_temp_dir() . '/webmention-test.log';
        @unlink($log);
        $this->fakeProfile('kim.example');
        parse_str((string) parse_url($this->startSignIn('https://kim.example/'), PHP_URL_QUERY), $params);

        // A server that answers the code redemption with an HTML page instead of the profile URL.
        $this->http->respond('POST', 'https://auth.example/auth', 200, "<html>\n  <body>Please   sign in</body></html>", ['Content-Type' => 'text/html; charset=utf-8']);
        $response = $this->request('GET', '/auth/callback', ['code' => 'secret-code', 'state' => $params['state']]);
        self::assertSame(400, $response->status);

        $written = (string) @file_get_contents($log);
        self::assertStringContainsString('Sign-in failed (indieauth) for https://kim.example/: indieauth_error: The authorization server did not return a valid response', $written);
        self::assertStringContainsString('endpoint https://auth.example/auth', $written);
        self::assertStringContainsString('HTTP 200', $written);
        self::assertStringContainsString('type text/html; charset=utf-8', $written);
        self::assertStringContainsString('body "<html> <body>Please sign in</body></html>"', $written, 'whitespace collapsed');
        self::assertStringNotContainsString('secret-code', $written);
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
