<?php

declare(strict_types=1);

namespace Webmention\Controllers;

use IndieAuth\Client;
use Webmention\Config;
use Webmention\Http\HttpException;
use Webmention\Http\Request;
use Webmention\Http\Response;
use Webmention\Http\Session;
use Webmention\Storage\AccountRepository;
use Webmention\View\Template;
use Webmention\Webmention\HttpClient;
use Webmention\Webmention\RateLimiter;

/**
 * Sign in with IndieAuth. The user's own authorization server is discovered
 * from their website.
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
        $this->configureClient();

        [$authorizationUrl, $error] = Client::begin($normalized);

        if ($error) {
            return $this->failure($error);
        }

        return Response::redirect((string) $authorizationUrl);
    }

    /** @param array<string, string> $params */
    public function callback(Request $request, array $params): Response
    {
        $this->session->start($request);
        $this->configureClient();

        [$response, $error] = Client::complete($request->query);

        if ($error) {
            return $this->failure($error);
        }

        $me = (string) $response['me'];

        if (parse_url($me, PHP_URL_QUERY) !== null) {
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
            return $this->failure($normalized);
        }

        $domain = self::domainFor($normalized);
        if (strlen($domain) > self::MAX_ACCOUNT_NAME_BYTES) {
            return $this->failure(['error_description' => 'Sorry, that profile URL is too long to use as an account name.']);
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

    private function configureClient(): void
    {
        Client::$clientID    = $this->config->baseUrl() . '/id';
        Client::$redirectURL = $this->config->baseUrl() . '/auth/callback';
        // Discovery fetches the URL someone typed in, so it goes through the safe transport too.
        Client::$http = $this->http->http(10);
    }

    /** @param array<string, mixed> $error */
    private function failure(array $error, int $status = 400): Response
    {
        return $this->page('message', 'Sign-in failed', [
            'heading' => 'Sign-in failed',
            'message' => trim((string) ($error['error_description'] ?? '') ?: (string) ($error['error'] ?? 'Unknown error')),
        ], status: $status);
    }
}
