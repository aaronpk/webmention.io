<?php

declare(strict_types=1);

namespace Webmention\Controllers;

use IndieAuth\Client;
use Webmention\Config;
use Webmention\Http\Request;
use Webmention\Http\Response;
use Webmention\Http\Session;
use Webmention\Storage\AccountRepository;
use Webmention\View\Template;

/**
 * Sign in with IndieAuth. The user's own authorization server is discovered
 * from their website.
 */
final class AuthController extends Controller
{
    public function __construct(
        Template $view,
        private readonly Session $session,
        private readonly AccountRepository $accounts,
        private readonly Config $config,
    ) {
        parent::__construct($view);
    }

    /** @param array<string, string> $params */
    public function start(Request $request, array $params): Response
    {
        $me = trim((string) $request->query('me'));
        if ($me === '') {
            return Response::redirect('/');
        }

        $this->session->start($request);
        $this->configureClient();

        [$authorizationUrl, $error] = Client::begin($me);

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

        $domain  = self::domainFor($me);
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
        $this->session->logOut();

        return Response::redirect('/');
    }

    /**
     * The account name for a profile URL: "https://example.com/" is
     * "example.com", "https://example.com/~me/" is "example.com_~me_".
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
    }

    /** @param array<string, mixed> $error */
    private function failure(array $error): Response
    {
        return $this->page('message', 'Sign-in failed', [
            'heading' => 'Sign-in failed',
            'message' => trim((string) ($error['error_description'] ?? '') ?: (string) ($error['error'] ?? 'Unknown error')),
        ], status: 400);
    }
}
