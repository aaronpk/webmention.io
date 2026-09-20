<?php

declare(strict_types=1);

namespace Webmention\Controllers;

use Webmention\Config;
use Webmention\Http\JsonResponder;
use Webmention\Http\Request;
use Webmention\Http\Response;
use Webmention\Http\Session;
use Webmention\Kernel;
use Webmention\Storage\AccountRepository;
use Webmention\View\Template;

final class HomeController extends Controller
{
    use RequiresLogin;

    public function __construct(
        Template $view,
        private readonly Session $session,
        private readonly AccountRepository $accounts,
        private readonly JsonResponder $json,
        private readonly Config $config,
    ) {
        parent::__construct($view);
    }

    protected function session(): Session
    {
        return $this->session;
    }

    protected function accounts(): AccountRepository
    {
        return $this->accounts;
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $user = $request->header('cookie') !== null && str_contains((string) $request->header('cookie'), Session::COOKIE)
            ? $this->currentUser($request)
            : null;

        $response = $this->page('home', 'Webmention.io', [
            'base_url'  => $this->config->baseUrl(),
            'signed_in' => $user !== null,
            'error'     => $user === null ? null : $this->session->takeFlash('error'),
            'me'        => trim((string) $request->query('me')),
        ], $user === null ? null : $this->nav($user, 'home'));

        // The sign-in form goes to /auth/start, which redirects to the user's own
        // authorization server. Browsers apply form-action to every hop of that
        // redirect, and the server could be on any origin.
        return $response->withHeader('content-security-policy', Kernel::csp("'self' https: http:"));
    }

    /**
     * The API documentation, with the rendering script running against the
     * example feed as a live demo. Public, but the nav shows when signed in.
     *
     * @param array<string, string> $params
     */
    public function api(Request $request, array $params): Response
    {
        $user = $request->header('cookie') !== null && str_contains((string) $request->header('cookie'), Session::COOKIE)
            ? $this->currentUser($request)
            : null;

        $response = $this->page('api', 'API documentation', [
            'base_url' => $this->config->baseUrl(),
        ], $user === null ? null : $this->nav($user, 'api'));

        // The demo fetches the example feed from this origin.
        return $response->withHeader('content-security-policy', Kernel::csp("'self'", "'self'"));
    }

    /**
     * The IndieAuth client metadata document. The client_id is this URL.
     *
     * @param array<string, string> $params
     */
    public function clientMetadata(Request $request, array $params): Response
    {
        $base = $this->config->baseUrl();

        return $this->json->respond($request, 200, [
            'client_id'     => $base . '/id',
            'client_name'   => 'webmention.io',
            'client_uri'    => $base,
            'logo_uri'      => $base . '/img/webmention-logo-380.png',
            'redirect_uris' => [$base . '/auth/callback'],
        ]);
    }
}
