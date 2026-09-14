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
use Webmention\Storage\PageRepository;
use Webmention\Webmention\HttpClient;
use Webmention\Webmention\RateLimiter;
use Webmention\Webmention\SiteVerifier;
use Webmention\Webmention\TargetResolver;

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
        private readonly TargetResolver $targets,
        private readonly PageRepository $pages,
        private readonly RateLimiter $limiter,
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

        // New accounts start with the domain they signed in with, which
        // IndieAuth has already proved is theirs.
        if ($sites === [] && ($domain = self::normalizeDomain((string) $user->domain)) !== null) {
            $site = $this->sites->findOrCreate($user->id, $domain);
            if (!$site->isVerified()) {
                $this->sites->markVerified($site->id);
            }
            $sites = [$this->sites->find($site->id) ?? $site];
        }

        $rows = [];
        foreach ($sites as $site) {
            $checked = $site->verificationCheckedAt === null ? null : date_create_immutable($site->verificationCheckedAt . ' UTC');
            $rows[] = [
                'id'         => $site->id,
                'domain'     => (string) $site->domain,
                'pages'      => $this->sites->pageCount($site->id),
                'mentions'   => $this->sites->linkCount($site->id),
                'verified'   => $site->isVerified(),
                'checked_on' => $checked === false || $checked === null ? null : $checked->format('M j, Y'),
                'error'      => $site->verificationError,
            ];
        }

        return $this->page('sites', 'Sites', [
            'sites'    => $rows,
            'endpoint' => $this->config->baseUrl() . '/' . $user->domain . '/webmention',
            'error'    => $request->query('error'),
            'merged'   => $request->query('merged'),
            'merge_error' => $request->query('merge_error'),
            'checked'  => $request->query('checked'),
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

        $site = $this->sites->findOrCreate($user->id, $domain);
        $this->sites->markVerified($site->id);

        return Response::seeOther('/settings/sites');
    }

    /**
     * Re-check whether a site advertises this account's endpoint, on request
     * from the Sites page. Legacy sites were never checked when added.
     *
     * @param array<string, string> $params
     */
    public function verifySite(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }
        $this->checkCsrf($request);

        $site = $this->sites->findForAccount($user->id, (int) $request->post('site_id'));
        if ($site === null) {
            throw HttpException::notFound('That site is not on your account.');
        }

        // Each check fetches the site; a handful a minute is plenty.
        if (!$this->limiter->allow('verify_site', (string) $user->id, 10, 60)) {
            return Response::seeOther('/settings/sites?checked=' . rawurlencode('Too many checks in a row; try again in a minute.'));
        }

        $problem = $this->verifier->verify($user, (string) $site->domain, $this->sites->recentPageHrefs($site->id));

        if ($problem === null) {
            $this->sites->markVerified($site->id);

            return Response::seeOther('/settings/sites?checked=' . rawurlencode("{$site->domain} is verified."));
        }

        $this->sites->markChecked($site->id, $problem);

        return Response::seeOther('/settings/sites?checked=' . rawurlencode("{$site->domain} could not be verified. $problem"));
    }

    /**
     * A page moved: the old URL now redirects (or points its rel=canonical) to
     * a new one. Its mentions are re-filed under the new URL, and the old URL
     * becomes an alias of it (issue 92).
     *
     * @param array<string, string> $params
     */
    public function mergePage(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }
        $this->checkCsrf($request);

        $fail = static fn (string $why): Response => Response::seeOther('/settings/sites?merge_error=' . rawurlencode($why));

        $old = trim((string) $request->post('old_url'));
        if (!Url::isHttp($old)) {
            return $fail('Enter the old URL of a page on one of your sites.');
        }

        $site = $this->sites->findByAccountAndDomain($user->id, (string) Url::host($old));
        if ($site === null) {
            return $fail('That URL is not on one of your sites.');
        }

        $from = $this->targets->existingPageFor($site, $old);
        if ($from === null) {
            return $fail('No mentions have been received for that URL.');
        }

        $canonical = $this->targets->canonicalFor($site, $old);
        if ($canonical === null) {
            return $fail("$old could not be fetched, or it leads to a page that is not on your account.");
        }
        if ($canonical['url'] === $from->href) {
            return $fail("$old does not redirect anywhere; its mentions are already filed under it.");
        }

        $into = $this->pages->findBySiteAndHref($canonical['site']->id, $canonical['url'])
            ?? $this->pages->findByAlias($canonical['site']->id, $canonical['url'])
            ?? $this->pages->create($canonical['site']->accountId, $canonical['site']->id, $canonical['url']);

        $moved = $this->pages->merge($from, $into);

        return Response::seeOther('/settings/sites?merged=' . rawurlencode(sprintf(
            '%d mention%s from %s now filed under %s.',
            $moved,
            $moved === 1 ? '' : 's',
            $old,
            $canonical['url'],
        )));
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

    /** Blocked URLs shown per page on the Blocklists page. */
    public const BLOCKED_URLS_PER_PAGE = 50;

    /** @param array<string, string> $params */
    public function blocks(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }

        $filter  = mb_substr(trim((string) $request->query('q')), 0, 200);
        $page    = max(0, (int) $request->query('page'));
        $perPage = self::BLOCKED_URLS_PER_PAGE;

        $total    = $this->blocks->countSourcesForAccount($user->id);
        $matching = $filter === '' ? $total : $this->blocks->countSourcesForAccount($user->id, $filter);
        $pages    = max(1, (int) ceil($matching / $perPage));
        $page     = min($page, $pages - 1);

        $sources = [];
        foreach ($this->blocks->sourcesForAccount($user->id, $filter, $perPage, ApiController::offset($page, $perPage)) as $row) {
            $date      = $row['created_at'] === null ? null : date_create_immutable($row['created_at'] . ' UTC');
            $sources[] = [
                ...$row,
                'url'        => ApiController::safeUrl($row['source']),
                'blocked_on' => $date === false || $date === null ? null : $date->format('M j, Y'),
            ];
        }

        return $this->page('blocks', 'Blocklists', [
            'domains'  => $this->blocks->domainsForAccount($user->id),
            'sources'  => $sources,
            'total'    => $total,
            'matching' => $matching,
            'q'        => $filter,
            'page'     => $page,
            'pages'    => $pages,
            'csrf'     => $this->session->csrfToken(),
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
