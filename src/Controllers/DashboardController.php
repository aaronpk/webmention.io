<?php

declare(strict_types=1);

namespace Webmention\Controllers;

use Webmention\Format\Jf2Format;
use Webmention\Format\Url;
use Webmention\Http\Request;
use Webmention\Http\Response;
use Webmention\Http\Session;
use Webmention\Model\Link;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\BlockRepository;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\SiteRepository;
use Webmention\View\Template;
use Webmention\Webmention\WebHooks;

/**
 * Recent mentions, and deleting or blocking them.
 */
final class DashboardController extends Controller
{
    use RequiresLogin;

    public function __construct(
        Template $view,
        private readonly Session $session,
        private readonly AccountRepository $accounts,
        private readonly SiteRepository $sites,
        private readonly LinkRepository $links,
        private readonly BlockRepository $blocks,
        private readonly WebHooks $webHooks,
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

        return $this->page('dashboard', 'Dashboard', [
            'links' => array_map(self::row(...), $this->links->recentForAccount($user->id, 40)),
        ], $this->nav($user, 'dashboard'));
    }

    /**
     * Preview what a delete would remove, with a choice of scope.
     *
     * @param array<string, string> $params
     */
    public function confirmDelete(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }

        $source = trim((string) $request->query('source'));
        $id     = $request->query('id');

        $link    = $id === null ? null : $this->links->findForAccount($user->id, (int) $id);
        $matches = $source === '' ? [] : $this->links->fromSourceForAccount($user->id, $source);
        $domain  = $link?->domain ?? ($source === '' ? null : Url::host($source));

        return $this->page('delete', 'Delete', [
            'link'         => $link === null ? null : self::row($link),
            'source'       => $matches === [] ? null : $matches[0]->href,
            'links'        => array_map(self::row(...), $matches),
            'domain'       => $domain,
            'domain_count' => $domain === null ? 0 : $this->links->countFromDomainForAccount($user->id, $domain),
            'csrf'         => $this->session->csrfToken(),
        ], $this->nav($user, 'dashboard'));
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }
        $this->checkCsrf($request);

        // One webmention: delete it and block its source for that site.
        if (($id = $request->post('id')) !== null) {
            $link = $this->links->findForAccount($user->id, (int) $id);
            if ($link === null) {
                return Response::seeOther('/dashboard');
            }

            $this->links->markDeleted($link->id);
            $this->blocks->blockSource($link->siteId, (string) $link->href);
            $this->notifyDeleted($link);
        }

        // Everything from one source URL: delete and block it on every site.
        if (($source = $request->post('source')) !== null && $source !== '') {
            foreach ($this->links->fromSourceForAccount($user->id, $source) as $link) {
                $this->links->markDeleted($link->id);
                $this->notifyDeleted($link);
            }
            foreach ($this->sites->listForAccount($user->id) as $site) {
                $this->blocks->blockSource($site->id, $source);
            }
        }

        // A whole domain: delete everything from it and refuse future mentions.
        if (($domain = $request->post('domain')) !== null && $domain !== '') {
            $this->links->markDeletedFromDomainForAccount($user->id, $domain);
            if (!$this->blocks->isDomainBlocked($user->id, $domain)) {
                $this->blocks->blockDomain($user->id, $domain);
            }
        }

        return Response::seeOther('/dashboard');
    }

    /** @param array<string, string> $params */
    public function unblock(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }
        $this->checkCsrf($request);

        $this->blocks->unblockDomain($user->id, (string) $request->post('domain'));

        return Response::seeOther('/settings/blocks');
    }

    private function notifyDeleted(Link $link): void
    {
        $site = $this->sites->find($link->siteId);
        if ($site !== null) {
            $this->webHooks->deleted($site, (string) $link->href, (string) $link->targetHref, $link->isPrivate);
        }
    }

    /** @return array<string, mixed> */
    public static function row(Link $link): array
    {
        return [
            'id'          => $link->id,
            'href'        => (string) $link->href,
            'source_url'  => ApiController::safeUrl($link->href),
            'target'      => (string) $link->targetHref,
            'target_url'  => ApiController::safeUrl($link->targetHref),
            'author_name' => (string) $link->authorName,
            'author_url'  => Url::blank($link->authorUrl) ? null : ApiController::safeUrl($link->authorUrl),
            'photo'       => Url::blank($link->authorPhoto) ? null : ApiController::safeUrl(Jf2Format::avatarUrl($link->authorPhoto)),
            'type'        => Jf2Format::relation($link->type),
            'received'    => $link->createdDate()?->format('M j, Y'),
            'delete_url'  => '/delete?source=' . rawurlencode((string) $link->href) . '&id=' . $link->id,
        ];
    }
}
