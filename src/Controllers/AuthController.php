<?php

declare(strict_types=1);

namespace Webmention\Controllers;

use IndieAuth\Client;
use Webmention\Config;
use Webmention\Http\HttpException;
use Webmention\Http\Request;
use Webmention\Http\Response;
use Webmention\Http\Session;
use Webmention\Logging\Log;
use Webmention\Storage\AccountRepository;
use Webmention\View\Template;
use Webmention\Webmention\HttpClient;
use Webmention\Webmention\PinnedHttp;
use Webmention\Webmention\RateLimiter;

/**
 * Sign in with IndieAuth. The user's own authorization server is discovered
 * from their website: indieauth-metadata first, then the older
 * rel="authorization_endpoint" link (the library tries both). A site with
 * neither, typically one that only has rel="me" links, is handed to
 * indielogin.com, which does RelMeAuth on our behalf and returns the
 * profile URL the same way.
 *
 * Starting a sign-in is a POST from this site's own form. A GET link could be
 * planted anywhere and would begin a flow in the visitor's session with the
 * attacker's website, whose authorization server then learns the state and
 * can finish the sign-in in the victim's browser, leaving them signed in to
 * the attacker's account. It would also make this origin redirect anywhere.
 */
final class AuthController extends Controller
{
    /** Discovery fetches whatever URL is typed in, so starts are metered per client. */
    private const START_LIMIT  = 10;
    private const START_WINDOW = 60;

    /** accounts.domain is varchar(255); a longer name would be cut and never match again. */
    private const MAX_ACCOUNT_NAME_BYTES = 255;

    public function __construct(
        Template $view,
        private readonly Session $session,
        private readonly AccountRepository $accounts,
        private readonly HttpClient $http,
        private readonly RateLimiter $limiter,
        private readonly Config $config,
        private readonly Log $log,
    ) {
        parent::__construct($view);
    }

    /**
     * Old links to GET /auth/start?me= land on the home page with the address
     * filled in. Nothing is fetched and nobody is redirected.
     *
     * @param array<string, string> $params
     */
    public function startForm(Request $request, array $params): Response
    {
        $me = trim((string) $request->query('me'));

        return Response::redirect($me === '' ? '/' : '/?me=' . rawurlencode($me));
    }

    /** @param array<string, string> $params */
    public function start(Request $request, array $params): Response
    {
        if (!$request->fromSameOrigin($this->config->baseUrl())) {
            throw HttpException::forbidden('Sign in from the form on this site.');
        }

        $me = trim((string) $request->post('me'));
        if ($me === '') {
            return Response::redirect('/');
        }

        if (!$this->limiter->allow('auth_start', $request->ip, self::START_LIMIT, self::START_WINDOW)) {
            return $this->failure(['error_description' => 'Too many sign-in attempts. Please wait a minute and try again.'], 429);
        }

        $normalized = self::normalizeMe($me);
        if (is_array($normalized)) {
            return $this->failure($normalized);
        }

        $this->session->start($request);
        $http = $this->configureClient();

        [$authorizationUrl, $error] = Client::begin($normalized);

        if ($error) {
            if (($error['error'] ?? '') === 'missing_authorization_endpoint') {
                // The library says the same thing whether the page had no
                // endpoint or could not be fetched at all; the transport
                // remembers how the fetch went.
                $status = $http->firstStatus();
                if ($status === null || $status < 200 || $status >= 400) {
                    return $this->failure([
                        'error'             => 'unreachable',
                        'error_description' => 'Your website could not be fetched' . ($status ? " (HTTP $status)" : '') . '. Check the address and that the site is up, then try again.',
                    ], me: $normalized, via: 'indieauth');
                }

                // A site with no IndieAuth endpoint at all: let indielogin.com
                // sign them in by their rel="me" links, as the old site did.
                if (($indielogin = $this->indieloginUrl()) !== null) {
                    return $this->startIndielogin($indielogin, $normalized);
                }
            }

            return $this->failure($error, me: $normalized, via: 'indieauth');
        }

        return Response::redirect((string) $authorizationUrl);
    }

    /** @param array<string, string> $params */
    public function callback(Request $request, array $params): Response
    {
        $this->session->start($request);

        if (isset($_SESSION['indielogin_state']) && !isset($_SESSION['indieauth_state'])) {
            return $this->completeIndielogin($request);
        }

        $this->configureClient();
        // The library clears its session data on failure, so note now which
        // endpoint the code is about to be redeemed at (for the log) and the
        // PKCE verifier (for a second attempt at the token endpoint).
        $entered  = isset($_SESSION['indieauth_entered_url']) ? (string) $_SESSION['indieauth_entered_url'] : null;
        $authEndpoint = isset($_SESSION['indieauth_authorization_endpoint']) ? (string) $_SESSION['indieauth_authorization_endpoint'] : null;
        $endpoint = isset($_SESSION['indieauth_token_endpoint']) ? (string) $_SESSION['indieauth_token_endpoint'] : $authEndpoint;
        $verifier = isset($_SESSION['indieauth_code_verifier']) ? (string) $_SESSION['indieauth_code_verifier'] : null;

        [$response, $error] = Client::complete($request->query);

        if ($error) {
            // The exchange happened (state checked, code sent) but the
            // authorization endpoint did not answer with a profile URL. Some
            // older servers only redeem codes at their token endpoint; try
            // there before giving up.
            if (isset($error['debug']) && $entered !== null && $authEndpoint !== null && $verifier !== null && $endpoint === $authEndpoint) {
                $retried = $this->redeemAtTokenEndpoint($request, $entered, $authEndpoint, $verifier, $error);
                if ($retried !== null) {
                    return $retried;
                }
            }

            return $this->failure($error, me: $entered, via: 'indieauth', endpoint: $endpoint);
        }

        return $this->signInAs((string) $response['me'], 'indieauth');
    }

    /**
     * Redeem the code at the site's token endpoint, when it advertises one
     * that differs from the authorization endpoint just tried. Returns null
     * when there is no such endpoint, so the original failure stands.
     *
     * @param array<string, mixed> $original The failure from the first attempt.
     */
    private function redeemAtTokenEndpoint(Request $request, string $entered, string $authEndpoint, string $verifier, array $original): ?Response
    {
        $tokenEndpoint = Client::discoverTokenEndpoint($entered);
        if (!is_string($tokenEndpoint) || $tokenEndpoint === '' || $tokenEndpoint === $authEndpoint) {
            return null;
        }

        $this->log->info("Sign-in for $entered: $authEndpoint did not redeem the code" . self::describeExchange($original['debug'] ?? null, null) . "; trying token endpoint $tokenEndpoint");

        $data = Client::exchangeAuthorizationCode($tokenEndpoint, [
            'code'          => (string) $request->query('code'),
            'redirect_uri'  => Client::$redirectURL,
            'client_id'     => Client::$clientID,
            'code_verifier' => $verifier,
        ]);

        $me = $data['response']['me'] ?? null;
        if (!is_string($me) || $me === '') {
            return $this->failure([
                'error'             => (string) ($data['response']['error'] ?? 'indieauth_error'),
                'error_description' => (string) ($data['response']['error_description'] ?? 'The authorization server did not return a valid response'),
                'debug'             => $data,
            ], me: $entered, via: 'indieauth', endpoint: $tokenEndpoint);
        }

        // As the library does: a different profile URL than was entered must
        // declare the same authorization endpoint, or anyone's server could
        // vouch for anyone's URL.
        $me = (string) Client::normalizeMeURL($me);
        if ($me !== $entered && Client::discoverAuthorizationEndpoint($me) !== $authEndpoint) {
            return $this->failure([
                'error'             => 'invalid_authorization_endpoint',
                'error_description' => 'The authorization server of the returned profile URL did not match the initial authorization server',
            ], me: $entered, via: 'indieauth', endpoint: $tokenEndpoint);
        }

        return $this->signInAs($me, 'indieauth');
    }

    /**
     * Hand the sign-in to indielogin.com: PKCE and state as with any
     * authorization server. indielogin wants client_id to be the app's home
     * page, on the same host as redirect_uri.
     */
    private function startIndielogin(string $indielogin, string $me): Response
    {
        $state    = Client::generateStateParameter();
        $verifier = Client::generatePKCECodeVerifier();

        unset($_SESSION['indieauth_entered_url']);
        $_SESSION['indielogin_state']         = $state;
        $_SESSION['indielogin_code_verifier'] = $verifier;
        $_SESSION['indielogin_me']            = $me;

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return Response::redirect($indielogin . '/authorize?' . http_build_query([
            'me'                    => $me,
            'client_id'             => $this->config->baseUrl() . '/',
            'redirect_uri'          => $this->config->baseUrl() . '/auth/callback',
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]));
    }

    private function completeIndielogin(Request $request): Response
    {
        $indielogin = $this->indieloginUrl() ?? 'https://indielogin.com';
        $me         = (string) ($_SESSION['indielogin_me'] ?? '');
        $state      = (string) ($_SESSION['indielogin_state'] ?? '');
        $verifier   = (string) ($_SESSION['indielogin_code_verifier'] ?? '');
        unset($_SESSION['indielogin_state'], $_SESSION['indielogin_code_verifier'], $_SESSION['indielogin_me']);

        $fail = fn (string $code, string $description): Response => $this->failure(['error' => $code, 'error_description' => $description], me: $me, via: 'indielogin');

        if ($request->query('error') !== null) {
            return $fail((string) $request->query('error'), (string) ($request->query('error_description') ?? 'indielogin.com reported an error.'));
        }
        if ($state === '' || !hash_equals($state, (string) $request->query('state'))) {
            return $fail('invalid_state', 'The sign-in did not come back the way it started. Please try again.');
        }
        if ($request->query('iss') !== null && (string) $request->query('iss') !== $indielogin . '/') {
            return $fail('invalid_issuer', 'The sign-in came back from an unexpected server.');
        }
        if (($code = (string) $request->query('code')) === '') {
            return $fail('invalid_response', 'indielogin.com did not return an authorization code.');
        }

        $response = $this->http->http(10)->post($indielogin . '/token', http_build_query([
            'code'          => $code,
            'client_id'     => $this->config->baseUrl() . '/',
            'redirect_uri'  => $this->config->baseUrl() . '/auth/callback',
            'code_verifier' => $verifier,
        ]), ['Content-Type: application/x-www-form-urlencoded;charset=UTF-8', 'Accept: application/json']);

        $data = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($data) || !is_string($data['me'] ?? null) || $data['me'] === '') {
            return $fail(
                (string) (is_array($data) ? ($data['error'] ?? '') : '') ?: 'indielogin_error',
                (string) (is_array($data) ? ($data['error_description'] ?? '') : '')
                    ?: (string) ($response['error_description'] ?? $response['error'] ?? '')
                    ?: 'indielogin.com did not confirm the sign-in (HTTP ' . (int) ($response['code'] ?? 0) . ').',
            );
        }

        return $this->signInAs($data['me'], 'indielogin');
    }

    /** Where to send people whose site has no IndieAuth endpoint; null when turned off. */
    private function indieloginUrl(): ?string
    {
        $url = rtrim(trim((string) $this->config->get('INDIELOGIN_URL', 'https://indielogin.com')), '/');

        return $url === 'off' || $url === '0' || !str_starts_with($url, 'https://') ? null : $url;
    }

    /**
     * The profile URL an authorization server (or indielogin.com) vouched
     * for becomes the account.
     */
    private function signInAs(string $me, string $via): Response
    {
        if (parse_url($me, PHP_URL_QUERY) !== null) {
            $this->log->warning("Sign-in failed ($via) for $me: profile URL has a query string");

            return $this->page('message', 'Sign-in failed', [
                'heading' => 'Sign-in failed',
                'message' => "Sorry, you can't use this service if your IndieAuth URL contains a query string. You signed in as $me. "
                    . 'If you want to host your website at a subfolder, make sure your root domain redirects with a temporary HTTP 302 redirect.',
            ], status: 400);
        }

        // The server may return a different profile URL than was entered; it
        // has to meet the same rules.
        $normalized = self::normalizeMe($me);
        if (is_array($normalized)) {
            return $this->failure($normalized, me: $me, via: $via);
        }

        $domain = self::domainFor($normalized);
        if (strlen($domain) > self::MAX_ACCOUNT_NAME_BYTES) {
            return $this->failure(['error_description' => 'Sorry, that profile URL is too long to use as an account name.'], me: $me, via: $via);
        }

        $account = $this->accounts->findByDomain($domain);

        if ($account === null) {
            $account = $this->accounts->create($domain);
        } else {
            $this->accounts->recordLogin($account->id);
        }

        $this->session->logIn($account->id);

        // Nothing received yet: start with setting up a site.
        return Response::redirect($this->accounts->hasVerifiedLinks($account->id) ? '/dashboard' : '/settings/sites');
    }

    /** @param array<string, string> $params */
    public function logout(Request $request, array $params): Response
    {
        $this->session->start($request);

        if (!$this->session->validCsrf($request->post('csrf'))) {
            throw HttpException::forbidden('Your session expired. Go back, reload the page and try again.');
        }

        $this->session->logOut();

        return Response::redirect('/');
    }

    /**
     * A profile URL fit to sign in with, or an error to show.
     *
     * Only https is accepted: http://example.com/ and https://example.com/
     * would name the same account, so whoever can answer plain HTTP for a
     * host could otherwise sign in as its HTTPS site. Ports, userinfo and
     * fragments are not allowed in a profile URL, and an internationalised
     * host is stored in its punycode form so one site is one account.
     *
     * @return string|array{error_description: string}
     */
    public static function normalizeMe(string $me): string|array
    {
        $me = trim($me);
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $me) !== 1) {
            $me = 'https://' . $me;
        }

        $parts = parse_url($me);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            return ['error_description' => 'Enter your website address, like https://example.com'];
        }

        if (strtolower($parts['scheme'] ?? '') !== 'https') {
            return ['error_description' => 'Your website address must start with https://. Sign in with the https version of your site.'];
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            return ['error_description' => 'Your website address must not contain a port, a username or a #fragment.'];
        }

        $host = strtolower($parts['host']);
        if (preg_match('/[^\x21-\x7e]/', $host) === 1) {
            if (!function_exists('idn_to_ascii')) {
                return ['error_description' => 'Sorry, internationalised domain names are not supported here yet.'];
            }
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                return ['error_description' => 'That does not look like a valid domain name.'];
            }
            $host = $ascii;
        }

        if (preg_match('/^[a-z0-9.\-]+$/', $host) !== 1 || !str_contains($host, '.')) {
            return ['error_description' => 'That does not look like a valid domain name.'];
        }

        return 'https://' . $host . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * The account name for a profile URL: "https://example.com/" is
     * "example.com", "https://example.com/~me/" is "example.com_~me_".
     *
     * The whole URL is lowercased, as it always has been, so existing accounts
     * keep matching.
     */
    public static function domainFor(string $me): string
    {
        $me = strtolower($me);
        $me = (string) preg_replace('#^(https?://[^/]+)/$#', '$1', $me);
        $me = (string) preg_replace('#^https?://#', '', $me);

        return str_replace('/', '_', $me);
    }

    /**
     * What the authorization server actually answered when a code was
     * redeemed, for the log: the endpoint, the HTTP status, the content type
     * and the start of the body. The library passes its exchange data as
     * "debug" on that kind of failure. The code and verifier are not in it.
     *
     * @param mixed $debug
     */
    public static function describeExchange(mixed $debug, ?string $endpoint): string
    {
        if ($endpoint === null && !is_array($debug)) {
            return '';
        }

        $parts = [];
        if ($endpoint !== null) {
            $parts[] = "endpoint $endpoint";
        }

        if (is_array($debug)) {
            $details = is_array($debug['response_details'] ?? null) ? $debug['response_details'] : [];
            $code    = (int) ($debug['response_code'] ?? $details['code'] ?? 0);
            $parts[] = $code > 0 ? "HTTP $code" : 'no HTTP response';

            $transportError = trim((string) ($details['error_description'] ?? '') ?: (string) ($details['error'] ?? ''));
            if ($transportError !== '') {
                $parts[] = "transport: $transportError";
            }

            if (preg_match('/^content-type:\s*([^\r\n]+)/im', (string) ($details['header'] ?? ''), $m) === 1) {
                $parts[] = 'type ' . trim($m[1]);
            }

            $body = trim((string) preg_replace('/\s+/', ' ', (string) ($debug['raw_response'] ?? '')));
            $parts[] = $body === '' ? 'empty body' : 'body "' . mb_strimwidth($body, 0, 300, '…') . '"';
        }

        return ' [' . implode('; ', $parts) . ']';
    }

    private function configureClient(): PinnedHttp
    {
        Client::$clientID    = $this->config->baseUrl() . '/id';
        Client::$redirectURL = $this->config->baseUrl() . '/auth/callback';
        // Discovery fetches the URL someone typed in, so it goes through the safe transport too.
        Client::$http = $this->http->http(10);

        return Client::$http;
    }

    /**
     * Show the failure, and log it so a report can be traced: which site,
     * which path (indieauth or indielogin) and what went wrong. Codes and
     * state values are never logged.
     *
     * @param array<string, mixed> $error
     */
    private function failure(array $error, int $status = 400, ?string $me = null, string $via = 'indieauth', ?string $endpoint = null): Response
    {
        $description = trim((string) ($error['error_description'] ?? '') ?: (string) ($error['error'] ?? 'Unknown error'));

        $this->log->warning(sprintf(
            'Sign-in failed (%s) for %s: %s%s%s',
            $via,
            $me ?? '(no profile URL)',
            isset($error['error']) ? $error['error'] . ': ' : '',
            $description,
            self::describeExchange($error['debug'] ?? null, $endpoint),
        ));

        return $this->page('message', 'Sign-in failed', [
            'heading' => 'Sign-in failed',
            'message' => $description,
        ], status: $status);
    }
}
