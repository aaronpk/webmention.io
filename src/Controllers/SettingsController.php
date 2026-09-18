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
use Webmention\Model\WebhookDelivery;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\BlockRepository;
use Webmention\Storage\SiteRepository;
use Webmention\Storage\WebhookDeliveryRepository;
use Webmention\View\Template;
use Webmention\Model\Mute;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\MuteRepository;
use Webmention\Storage\PageRepository;
use Webmention\Webmention\HttpClient;
use Webmention\Webmention\Moderation;
use Webmention\Webmention\AccountMerger;
use Webmention\Webmention\RateLimiter;
use Webmention\Webmention\SiteActivity;
use Webmention\Webmention\SiteDeleter;
use Webmention\Webmention\WebHooks;
use Webmention\Webmention\SiteVerifier;
use Webmention\Webmention\TargetResolver;

/**
 * Account settings: API token, sites and their web hooks, blocked domains.
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
        private readonly LinkRepository $links,
        private readonly MuteRepository $mutes,
        private readonly WebHooks $webHooks,
        private readonly WebhookDeliveryRepository $deliveries,
        private readonly SiteActivity $activity,
        private readonly AccountMerger $merger,
        private readonly SiteDeleter $deleter,
        private readonly Config $config,
    ) {
        parent::__construct($view);
    }

    protected function pendingCount(Account $user): ?int
    {
        return $this->links->countPendingForAccount($user->id);
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
            'token'      => $token,
            'html_url'   => $base . '/api/mentions.html?token=' . rawurlencode($token),
            'atom_url'   => $base . '/api/mentions.atom?token=' . rawurlencode($token),
            'export_url' => $base . '/api/export.jf2?token=' . rawurlencode($token),
            'merge_error' => $request->query('merge_error'),
            'merged'      => $request->query('merged'),
            'csrf'       => $this->session->csrfToken(),
        ], $this->nav($user, 'settings'));
    }

    /**
     * Step one of bringing an old account in (issue 223): check that its
     * domain now points here, then ask for confirmation.
     *
     * @param array<string, string> $params
     */
    public function mergeAccount(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }
        $this->checkCsrf($request);

        // Each check fetches the old domain.
        if (!$this->limiter->allow('merge_check', (string) $user->id, 5, 60)) {
            return Response::seeOther('/settings?merge_error=' . rawurlencode('Too many checks in a row; try again in a minute.'));
        }

        $old = $this->merger->check($user, (string) $request->post('old_domain'));
        if (is_string($old)) {
            return Response::seeOther('/settings?merge_error=' . rawurlencode($old));
        }

        // Remember what was proved, so the confirmation cannot name another account.
        $_SESSION['merge_account'] = ['id' => $old->id, 'until' => time() + 600];

        return $this->page('merge', 'Merge accounts', [
            ...$this->merger->preview($old),
            'old_domain' => (string) $old->domain,
            'csrf'       => $this->session->csrfToken(),
        ], $this->nav($user, 'settings'));
    }

    /** @param array<string, string> $params */
    public function confirmMergeAccount(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }
        $this->checkCsrf($request);

        $pending = $_SESSION['merge_account'] ?? null;
        unset($_SESSION['merge_account']);
        if (!is_array($pending) || (int) ($pending['until'] ?? 0) < time()) {
            return Response::seeOther('/settings?merge_error=' . rawurlencode('That confirmation has expired; check the old domain again.'));
        }

        $old = $this->accounts->find((int) $pending['id']);
        if ($old === null || $old->id === $user->id || strtolower((string) $old->domain) !== AccountMerger::domain((string) $request->post('old_domain'))) {
            return Response::seeOther('/settings?merge_error=' . rawurlencode('That confirmation does not match; check the old domain again.'));
        }

        $moved = $this->merger->merge($user, $old);

        return Response::seeOther('/settings?merged=' . rawurlencode(sprintf(
            'Merged %s: %d site%s and %s webmention%s are now on this account.',
            $old->domain,
            $moved['sites'],
            $moved['sites'] === 1 ? '' : 's',
            number_format($moved['mentions']),
            $moved['mentions'] === 1 ? '' : 's',
        )));
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

        $rows     = [];
        $archived = [];
        foreach ($sites as $site) {
            if ($site->isArchived()) {
                // No counts here: an account can have hundreds of old sites.
                $archived[] = [
                    'id'          => $site->id,
                    'domain'      => (string) $site->domain,
                    'archived_on' => self::shortDate($site->archivedAt),
                    'deleting'    => $this->deleter->isDeleting($site->id),
                ];
                continue;
            }

            $rows[] = [
                'id'           => $site->id,
                'domain'       => (string) $site->domain,
                'pages'        => $this->sites->pageCount($site->id),
                'mentions'     => $this->sites->linkCount($site->id),
                'last_mention' => self::shortDate($this->sites->lastMentionAt($site->id)),
                'verified'     => $site->isVerified(),
            ];
        }

        return $this->page('sites', 'Sites', [
            'sites'    => $rows,
            'archived' => $archived,
            'notice'   => $request->query('notice'),
            'endpoint' => $this->config->baseUrl() . '/' . $user->domain . '/webmention',
            'error'    => $request->query('error'),
            'merged'   => $request->query('merged'),
            'merge_error' => $request->query('merge_error'),
            'csrf'     => $this->session->csrfToken(),
        ], $this->nav($user, 'sites'));
    }

    /**
     * Archive one or more sites: they refuse new webmentions but keep the
     * ones they have. From the Sites list's checkboxes or a site's own page.
     *
     * @param array<string, string> $params
     */
    public function archiveSites(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }
        $this->checkCsrf($request);

        $ids      = array_map('intval', $request->inputList('site_id'));
        $archived = $this->sites->archive($user->id, $ids);

        if (count($ids) === 1 && $request->post('back') === 'site' && $this->sites->findForAccount($user->id, $ids[0]) !== null) {
            return Response::seeOther("/settings/sites/{$ids[0]}?notice=" . rawurlencode('Archived. This site no longer accepts webmentions.'));
        }

        return Response::seeOther('/settings/sites?notice=' . rawurlencode(
            $archived === 0 ? 'No sites were archived.' : sprintf('Archived %d site%s.', $archived, $archived === 1 ? '' : 's'),
        ));
    }

    /** @param array<string, string> $params */
    public function unarchiveSite(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }
        $this->checkCsrf($request);

        $site = $this->sites->findForAccount($user->id, (int) $request->post('site_id'));
        if ($site === null) {
            throw HttpException::notFound('That site is not on your account.');
        }

        if ($this->deleter->isDeleting($site->id)) {
            return Response::seeOther("/settings/sites/{$site->id}?notice=" . rawurlencode('This site is being deleted and cannot be unarchived.'));
        }

        $this->sites->unarchive($user->id, $site->id);

        return Response::seeOther("/settings/sites/{$site->id}?notice=" . rawurlencode('Unarchived. This site accepts webmentions again.'));
    }

    /**
     * What deleting a site removes, with a way to keep a copy first.
     *
     * @param array<string, string> $params
     */
    public function confirmDeleteSite(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }

        $site = $this->sites->findForAccount($user->id, (int) ($params['id'] ?? 0));
        if ($site === null) {
            throw HttpException::notFound('That site is not on your account.');
        }

        return $this->page('site-delete', 'Delete ' . $site->domain, [
            'site' => [
                'id'       => $site->id,
                'domain'   => (string) $site->domain,
                'pages'    => $this->sites->pageCount($site->id),
                'mentions' => $this->sites->linkCount($site->id),
            ],
            'export_url'     => $this->config->baseUrl() . '/api/export.jf2?token=' . rawurlencode($this->tokenFor($user)) . '&domain=' . rawurlencode((string) $site->domain),
            'sign_in_domain' => strtolower((string) $site->domain) === self::normalizeDomain((string) $user->domain),
            'error'          => $request->query('error'),
            'csrf'           => $this->session->csrfToken(),
        ], $this->nav($user, 'sites'));
    }

    /** @param array<string, string> $params */
    public function deleteSite(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }
        $this->checkCsrf($request);

        $site = $this->sites->findForAccount($user->id, (int) $request->post('site_id'));
        if ($site === null) {
            throw HttpException::notFound('That site is not on your account.');
        }

        if (strtolower(trim((string) $request->post('confirm_domain'))) !== strtolower((string) $site->domain)) {
            return Response::seeOther("/settings/sites/{$site->id}/delete?error=" . rawurlencode('Type the domain name exactly to confirm.'));
        }

        $mentions = $this->sites->linkCount($site->id);

        return Response::seeOther('/settings/sites?notice=' . rawurlencode($this->deleter->delete($site)
            ? "Deleted {$site->domain}."
            : sprintf('Deleting %s. Its %s webmentions are being removed in the background.', $site->domain, number_format($mentions))));
    }

    /** A stored UTC datetime as "Sep 17, 2026", or null. */
    private static function shortDate(?string $utc): ?string
    {
        $d = $utc === null ? null : date_create_immutable($utc . ' UTC');

        return $d === false || $d === null ? null : $d->format('M j, Y');
    }

    /**
     * Everything about one site: verification, web hook, moderation, avatars.
     *
     * @param array<string, string> $params
     */
    public function site(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }

        $site = $this->sites->findForAccount($user->id, (int) ($params['id'] ?? 0));
        if ($site === null) {
            throw HttpException::notFound('That site is not on your account.');
        }

        $date = static function (?string $utc): ?string {
            $d = $utc === null ? null : date_create_immutable($utc . ' UTC');

            return $d === false || $d === null ? null : $d->format('M j, Y');
        };

        return $this->page('site', (string) $site->domain, [
            'site' => [
                'id'              => $site->id,
                'domain'          => (string) $site->domain,
                'pages'           => $this->sites->pageCount($site->id),
                'mentions'        => $this->sites->linkCount($site->id),
                'verified'        => $site->isVerified(),
                'verified_on'     => $date($site->verifiedAt),
                'checked_on'      => $date($site->verificationCheckedAt),
                'error'           => $site->verificationError,
                'callback_url'    => (string) $site->callbackUrl,
                'callback_secret' => (string) $site->callbackSecret,
                'archive_avatars' => $site->archiveAvatars,
                'moderation'      => $site->moderation ?? 'off',
                'archived'        => $site->isArchived(),
                'archived_on'     => $date($site->archivedAt),
                'deleting'        => $this->deleter->isDeleting($site->id),
            ],
            'notice'       => $request->query('notice'),
            'export_url'   => $site->isArchived()
                ? $this->config->baseUrl() . '/api/export.jf2?token=' . rawurlencode($this->tokenFor($user)) . '&domain=' . rawurlencode((string) $site->domain)
                : null,
            'activity'     => self::activity($this->activity->monthlyCounts($site->id)),
            'endpoint'     => $this->config->baseUrl() . '/' . $user->domain . '/webmention',
            'saved'        => $request->query('saved') !== null,
            'checked'      => $request->query('checked'),
            'deliveries'   => Url::blank($site->callbackUrl) ? [] : array_map(self::deliveryRow(...), $this->deliveries->recentForSite($site->id)),
            'has_mentions' => $this->links->latestPublishedForSite($site->id) !== null,
            'sent'         => $request->query('sent') !== null,
            'resend_error' => $request->query('resend_error'),
            'csrf'         => $this->session->csrfToken(),
        ], $this->nav($user, 'sites'));
    }

    /**
     * The sparkline's data: the monthly series with what the template needs to scale it.
     *
     * @param  list<array{month: string, label: string, count: int}> $months
     * @return array{months: list<array{month: string, label: string, count: int}>, max: int, total: int}
     */
    private static function activity(array $months): array
    {
        $counts = array_column($months, 'count');

        return ['months' => $months, 'max' => max(1, ...$counts), 'total' => array_sum($counts)];
    }

    /**
     * A delivery as the site page shows it.
     *
     * @return array<string, mixed>
     */
    private static function deliveryRow(WebhookDelivery $d): array
    {
        $payload = json_decode($d->requestBody, true);
        $pretty  = is_array($payload)
            ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : false;
        $when = date_create_immutable($d->createdAt . ' UTC');

        return [
            'id'       => $d->id,
            'when'     => $when === false ? $d->createdAt : $when->format('M j, Y H:i') . ' UTC',
            'kind'     => $d->kind,
            'ok'       => $d->succeeded(),
            'result'   => $d->result(),
            'duration' => $d->durationMs,
            'source'   => is_array($payload) ? (string) ($payload['source'] ?? '') : '',
            'target'   => is_array($payload) ? (string) ($payload['target'] ?? '') : '',
            'request'  => $pretty === false ? $d->requestBody : $pretty,
            'response' => $d->responseBody,
        ];
    }

    /**
     * Send a web hook by hand (issue 231): an earlier delivery again, or the
     * site's newest mention, so the owner can watch what their endpoint does.
     *
     * @param array<string, string> $params
     */
    public function resendWebhook(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }
        $this->checkCsrf($request);

        $site = $this->sites->findForAccount($user->id, (int) $request->post('site_id'));
        if ($site === null) {
            throw HttpException::notFound('That site is not on your account.');
        }
        if (Url::blank($site->callbackUrl)) {
            throw HttpException::badRequest('This site has no callback URL to send to.');
        }

        $back = "/settings/sites/{$site->id}";

        // Each send is a request to someone's server; a few a minute is plenty.
        if (!$this->limiter->allow('webhook_resend', (string) $user->id, 10, 60)) {
            return Response::seeOther("$back?resend_error=" . rawurlencode('Too many sends in a row; try again in a minute.') . '#deliveries');
        }

        if ($request->post('delivery_id') !== null) {
            $delivery = $this->deliveries->findForSite($site->id, (int) $request->post('delivery_id'));
            if ($delivery === null) {
                throw HttpException::notFound('That delivery is not on this site.');
            }
            $this->webHooks->resend($site, $delivery);
        } else {
            $link = $this->links->latestPublishedForSite($site->id);
            if ($link === null) {
                return Response::seeOther("$back?resend_error=" . rawurlencode('This site has no published webmention to send yet.') . '#deliveries');
            }
            $this->webHooks->notify($site, $link, (string) $link->href, (string) $link->targetHref, $link->isPrivate, 'test');
        }

        return Response::seeOther("$back?sent=1#deliveries");
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

        if (($existing = $this->sites->findByAccountAndDomain($user->id, $domain)) !== null) {
            // An archived site comes back from its own page, where Unarchive is.
            return $existing->isArchived()
                ? Response::seeOther("/settings/sites/{$existing->id}?notice=" . rawurlencode("$domain is already on your account, archived. Unarchive it to receive webmentions again."))
                : Response::seeOther('/settings/sites');
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
            return Response::seeOther("/settings/sites/{$site->id}?checked=" . rawurlencode('Too many checks in a row; try again in a minute.'));
        }

        $problem = $this->verifier->verify($user, (string) $site->domain, $this->sites->recentPageHrefs($site->id));

        if ($problem === null) {
            $this->sites->markVerified($site->id);

            return Response::seeOther("/settings/sites/{$site->id}?checked=" . rawurlencode("{$site->domain} is verified."));
        }

        $this->sites->markChecked($site->id, $problem);

        return Response::seeOther("/settings/sites/{$site->id}?checked=" . rawurlencode("{$site->domain} could not be verified. $problem"));
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

    /**
     * The Web Hooks page is gone: each site's settings live on its own page
     * under Sites. Old links land on the list.
     *
     * @param array<string, string> $params
     */
    public function webhooks(Request $request, array $params): Response
    {
        return Response::redirect('/settings/sites');
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

        $policy = (string) $request->post('moderation');
        if (!in_array($policy, Moderation::POLICIES, true)) {
            $policy = 'off';
        }

        $this->sites->updateWebhook(
            $site->id,
            $url,
            mb_substr((string) $request->post('callback_secret'), 0, 50),
            $request->post('archive_avatars') !== null,
            $policy,
        );

        return Response::seeOther("/settings/sites/{$site->id}?saved=1");
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
            'mutes'    => array_map(static fn (Mute $m): array => ['id' => $m->id, 'kind' => $m->kind, 'pattern' => $m->pattern, 'label' => $m->describe()], $this->mutes->forAccount($user->id)),
            'mute_notice' => $request->query('muted'),
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

    /**
     * Add a mute rule and hide what it already covers (issue 85).
     *
     * @param array<string, string> $params
     */
    public function mute(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }
        $this->checkCsrf($request);

        $kind    = (string) $request->post('kind');
        $pattern = Mute::normalizePattern((string) $request->post('pattern'));
        $back    = ReturnPath::resolve($request->post('back'), '/settings/blocks');
        $param   = $back === '/settings/blocks' ? 'muted' : 'notice';

        if (!in_array($kind, Mute::KINDS, true) || $pattern === null) {
            return Response::seeOther("$back?$param=" . rawurlencode('Enter a domain name like example.com, or a URL prefix like https://example.com/user/'));
        }

        $rule   = $this->mutes->add($user->id, $kind, $pattern);
        $hidden = $this->links->hideMatching($user->id, $rule);

        return Response::seeOther("$back?$param=" . rawurlencode(sprintf(
            'Muted %s. %d existing webmention%s hidden; new ones will be too.',
            lcfirst($rule->describe()),
            $hidden,
            $hidden === 1 ? '' : 's',
        )));
    }

    /**
     * Remove a mute rule; mentions no other rule covers become visible again.
     *
     * @param array<string, string> $params
     */
    public function unmute(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }
        $this->checkCsrf($request);

        $rule = $this->mutes->find($user->id, (int) $request->post('id'));
        if ($rule === null) {
            return Response::seeOther('/settings/blocks');
        }

        $this->mutes->remove($user->id, $rule->id);
        $restored = $this->links->restoreHidden($user->id, $this->mutes->forAccount($user->id));

        return Response::seeOther('/settings/blocks?muted=' . rawurlencode(sprintf(
            'Unmuted %s. %d webmention%s visible again.',
            lcfirst($rule->describe()),
            $restored,
            $restored === 1 ? '' : 's',
        )));
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
