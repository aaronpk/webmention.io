<?php

declare(strict_types=1);

namespace Webmention\Controllers;

use Webmention\Config;
use Webmention\Format\Url;
use Webmention\Http\HttpException;
use Webmention\Http\Request;
use Webmention\Http\Response;
use Webmention\Http\Session;
use Webmention\Model\Account;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\BlockRepository;
use Webmention\Storage\SiteRepository;
use Webmention\View\Template;
use Webmention\Webmention\HttpClient;
use Webmention\Webmention\SiteVerifier;

/**
 * Account settings: API token, sites, webhooks, blocked domains.
 */
final class SettingsController extends Controller
{
    use RequiresLogin;

    public function __construct(
        Template $view,
        private readonly Session $session,
        private readonly AccountRepository $accounts,
        private readonly SiteRepository $sites,
        private readonly BlockRepository $blocks,
        private readonly SiteVerifier $verifier,
        private readonly HttpClient $http,
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
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }

        $token = $this->tokenFor($user);
        $base  = $this->config->baseUrl();

        return $this->page('settings', 'Settings', [
            'token'    => $token,
            'html_url' => $base . '/api/mentions.html?token=' . rawurlencode($token),
            'atom_url' => $base . '/api/mentions.atom?token=' . rawurlencode($token),
            'csrf'     => $this->session->csrfToken(),
        ], $this->nav($user, 'settings'));
    }

    /** @param array<string, string> $params */
    public function changeToken(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }
        $this->checkCsrf($request);

        $this->accounts->regenerateToken($user->id);

        return Response::seeOther('/settings');
    }

    /** @param array<string, string> $params */
    public function sites(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }

        $sites = $this->sites->listForAccount($user->id);

        // New accounts start with the domain they signed in with.
        if ($sites === [] && ($domain = self::normalizeDomain((string) $user->domain)) !== null) {
            $sites = [$this->sites->create($user->id, $domain)];
        }

        $rows = [];
        foreach ($sites as $site) {
            $rows[] = [
                'domain'   => (string) $site->domain,
                'pages'    => $this->sites->pageCount($site->id),
                'mentions' => $this->sites->linkCount($site->id),
            ];
        }

        return $this->page('sites', 'Sites', [
            'sites'    => $rows,
            'endpoint' => $this->config->baseUrl() . '/' . $user->domain . '/webmention',
            'error'    => $request->query('error'),
            'csrf'     => $this->session->csrfToken(),
        ], $this->nav($user, 'sites'));
    }

    /** @param array<string, string> $params */
    public function createSite(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }
        $this->checkCsrf($request);

        $domain = self::normalizeDomain((string) $request->post('domain'));

        if ($domain === null) {
            return Response::seeOther('/settings/sites?error=' . rawurlencode('Enter a domain name, like example.com'));
        }

        if ($this->sites->findByAccountAndDomain($user->id, $domain) !== null) {
            return Response::seeOther('/settings/sites');
        }

        // The domain has to name this account's endpoint before it can be added.
        $problem = $this->verifier->verify($user, $domain);
        if ($problem !== null) {
            $tag = '<link rel="webmention" href="' . $this->verifier->endpointFor($user) . '">';

            return Response::seeOther('/settings/sites?error=' . rawurlencode(
                "$problem Add $tag to the home page of $domain, then try again.",
            ));
        }

        $this->sites->create($user->id, $domain);

        return Response::seeOther('/settings/sites');
    }

    /** @param array<string, string> $params */
    public function webhooks(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }

        $sites = [];
        foreach ($this->sites->listForAccount($user->id) as $site) {
            $sites[] = [
                'id'              => $site->id,
                'domain'          => (string) $site->domain,
                'callback_url'    => (string) $site->callbackUrl,
                'callback_secret' => (string) $site->callbackSecret,
                'archive_avatars' => $site->archiveAvatars,
            ];
        }

        return $this->page('webhooks', 'Web Hooks', [
            'sites'    => $sites,
            'saved'    => $request->query('saved'),
            'csrf'     => $this->session->csrfToken(),
        ], $this->nav($user, 'webhooks'));
    }

    /** @param array<string, string> $params */
    public function configureWebhook(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }
        $this->checkCsrf($request);

        $site = $this->sites->findForAccount($user->id, (int) $request->post('site_id'));
        if ($site === null) {
            throw HttpException::notFound('That site is not on your account.');
        }

        $url = trim((string) $request->post('callback_url'));
        if ($url !== '' && !Url::isHttp($url)) {
            throw HttpException::badRequest('The callback URL must be an http or https URL.');
        }
        if ($url !== '' && ($why = $this->http->blockedReason($url)) !== null) {
            throw HttpException::badRequest("That callback URL can't be reached from here: $why");
        }

        $this->sites->updateWebhook(
            $site->id,
            $url,
            mb_substr((string) $request->post('callback_secret'), 0, 50),
            $request->post('archive_avatars') !== null,
        );

        return Response::seeOther('/settings/webhooks?saved=' . $site->id);
    }

    /** @param array<string, string> $params */
    public function blocks(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }

        return $this->page('blocks', 'Blocklists', [
            'domains' => $this->blocks->domainsForAccount($user->id),
            'csrf'    => $this->session->csrfToken(),
        ], $this->nav($user, 'blocks'));
    }

    /** "https://Example.com/path" → "example.com". Null if there's no usable host. */
    public static function normalizeDomain(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $input) !== 1) {
            $input = 'http://' . $input;
        }

        $host = Url::host($input);

        return $host !== null && preg_match('/^[a-z0-9.\-_%]+$/', $host) === 1 && str_contains($host, '.') ? $host : null;
    }

    private function tokenFor(Account $user): string
    {
        return $user->token ?? $this->accounts->regenerateToken($user->id);
    }
}
