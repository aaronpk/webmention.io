<?php

declare(strict_types=1);

namespace Webmention\Controllers;

use Webmention\Config;
use Webmention\Http\JsonResponder;
use Webmention\Http\Request;
use Webmention\Http\Response;
use Webmention\Http\Session;
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

        return $this->page('home', 'Webmention.io', [
            'base_url'  => $this->config->baseUrl(),
            'signed_in' => $user !== null,
            'error'     => $request->query('error'),
        ], $user === null ? null : $this->nav($user, 'home'));
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
