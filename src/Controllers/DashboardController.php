<?php

declare(strict_types=1);

namespace Webmention\Controllers;

use Webmention\Format\Url;
use Webmention\Http\HttpException;
use Webmention\Http\Request;
use Webmention\Http\Response;
use Webmention\Http\Session;
use Webmention\Model\Account;
use Webmention\Model\Link;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\BlockRepository;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\SiteRepository;
use Webmention\View\MentionRow;
use Webmention\View\Template;
use Webmention\Webmention\AccountOverview;
use Webmention\Webmention\SourceActivity;
use Webmention\Webmention\WebHooks;

/**
 * Recent mentions, the review queue, and deleting, restoring or blocking.
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
        private readonly SourceActivity $sources,
        private readonly AccountOverview $overview,
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

    protected function pendingCount(Account $user): ?int
    {
        return $this->links->countPendingForAccount($user->id);
    }

    /** Waiting mentions shown on the dashboard before the link to the full queue. */
    private const PENDING_PREVIEW = 20;

    /** Waiting mentions per page on /moderation. */
    public const PENDING_PER_PAGE = 50;

    /** The most ids one approve or reject form may carry. */
    public const BULK_LIMIT = 200;

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }

        return $this->page('dashboard', 'Dashboard', [
            'overview'      => $this->overview->recent($user->id),
            'pending'       => array_map(MentionRow::row(...), $this->links->pendingForAccount($user->id, self::PENDING_PREVIEW)),
            'pending_total' => $this->links->countPendingForAccount($user->id),
            'links'         => array_map(MentionRow::row(...), $this->links->recentForAccount($user->id, 40)),
            'notice'        => $this->session->takeFlash('notice'),
            'csrf'          => $this->session->csrfToken(),
        ], $this->nav($user, 'dashboard'));
    }

    /**
     * Every mention awaiting review, paged.
     *
     * @param array<string, string> $params
     */
    public function moderation(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }

        $total = $this->links->countPendingForAccount($user->id);
        $pages = max(1, (int) ceil($total / self::PENDING_PER_PAGE));
        $page  = min(max(0, (int) $request->query('page')), $pages - 1);

        return $this->page('moderation', 'Awaiting review', [
            'links' => array_map(MentionRow::row(...), $this->links->pendingForAccount($user->id, self::PENDING_PER_PAGE, $page * self::PENDING_PER_PAGE)),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
            'csrf'  => $this->session->csrfToken(),
        ], $this->nav($user, 'dashboard'));
    }

    /**
     * Publish waiting or hidden mentions (`id`, one or several), or every
     * waiting mention from a source domain (`domain`). Publishing sends the
     * web hook it was holding back.
     *
     * @param array<string, string> $params
     */
    public function approve(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }
        $this->checkCsrf($request);

        $published = [];

        if (($ids = self::ids($request)) !== []) {
            foreach ($ids as $id) {
                $link = $this->links->findForAccount($user->id, $id);
                if ($link !== null && in_array($link->status, ['pending', 'hidden'], true) && !$link->deleted) {
                    $this->links->publish($link->id);
                    $published[] = $this->links->find($link->id) ?? $link;
                }
            }
        } elseif (($domain = trim((string) $request->post('domain'))) !== '') {
            $published = $this->links->publishPendingFromDomain($user->id, $domain);
        }

        foreach ($published as $link) {
            $site = $this->sites->find($link->siteId);
            if ($site !== null) {
                $this->webHooks->notify($site, $link, (string) $link->href, (string) $link->targetHref, $link->isPrivate);
            }
        }
        if ($published !== []) {
            $this->overview->forget($user->id);
        }

        return $this->backTo($request, sprintf('%d webmention%s approved.', count($published), count($published) === 1 ? '' : 's'));
    }

    /**
     * Refuse waiting mentions (`id`, one or several): each is deleted and its
     * source URL blocked, like a delete from the dashboard. No web hook,
     * since they were never announced.
     *
     * @param array<string, string> $params
     */
    public function reject(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }
        $this->checkCsrf($request);

        $rejected = 0;
        foreach (self::ids($request) as $id) {
            $link = $this->links->findForAccount($user->id, $id);
            if ($link === null || $link->status !== 'pending' || $link->deleted) {
                continue;
            }
            $this->links->markDeleted($link->id);
            $this->blocks->blockSource($link->siteId, (string) $link->href);
            $rejected++;
        }

        if ($rejected === 0) {
            return $this->backTo($request, 'That webmention is no longer waiting for review.');
        }

        return $this->backTo($request, $rejected === 1
            ? 'Webmention rejected; its source URL is blocked.'
            : "$rejected webmentions rejected; their source URLs are blocked.");
    }

    /**
     * Bring a deleted mention back. Its source URL is unblocked on that site
     * (deleting had blocked it) and the web hook is told, since it was told
     * about the deletion.
     *
     * @param array<string, string> $params
     */
    public function restore(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }
        $this->checkCsrf($request);

        $link = $this->links->findForAccount($user->id, (int) $request->post('id'));
        if ($link === null || !$link->deleted) {
            return $this->backTo($request, 'That webmention is not deleted.');
        }

        $this->links->restore($link->id);
        $this->blocks->unblockSource($link->siteId, (string) $link->href);
        $this->overview->forget($user->id);

        $link = $this->links->find($link->id) ?? $link;
        $site = $this->sites->find($link->siteId);
        if ($site !== null) {
            $this->webHooks->notify($site, $link, (string) $link->href, (string) $link->targetHref, $link->isPrivate);
        }

        $notice = 'Webmention restored; its source URL is unblocked.';
        if ($this->blocks->isDomainBlocked($user->id, $link->domain)) {
            $notice .= " Its domain, {$link->domain}, is still blocked.";
        }

        return $this->backTo($request, $notice);
    }

    /** @return list<int> The `id` field, one value or several, capped. */
    private static function ids(Request $request): array
    {
        $ids = [];
        foreach (array_slice($request->inputList('id'), 0, self::BULK_LIMIT) as $id) {
            if (ctype_digit($id)) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** Back to the page an action was taken from, with a message. */
    private function backTo(Request $request, string $notice): Response
    {
        return $this->flashTo(ReturnPath::resolve($request->post('back')), 'notice', $notice);
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
        // A domain on its own (from the Sources page) offers only the block.
        $domain  = $link?->domain ?? ($source === '' ? MentionsController::domain($request->query('domain')) : Url::host($source));

        return $this->page('delete', 'Delete', [
            'link'         => $link === null ? null : MentionRow::row($link),
            'source'       => $matches === [] ? null : $matches[0]->href,
            'links'        => array_map(MentionRow::row(...), $matches),
            'domain'       => $domain,
            'domain_count' => $domain === null ? 0 : $this->links->countFromDomainForAccount($user->id, $domain),
            'blocked'      => $domain !== null && $this->blocks->isDomainBlocked($user->id, $domain),
            'back'         => ReturnPath::resolve($request->query('back')),
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
        $this->overview->forget($user->id);

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
            $this->sources->forget($user->id);

            return $this->backTo($request, "Blocked $domain and deleted every webmention from it.");
        }

        return Response::seeOther(ReturnPath::resolve($request->post('back')));
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

    /**
     * Let a source URL send webmentions to one of the user's sites again.
     *
     * @param array<string, string> $params
     */
    public function unblockSource(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }
        $this->checkCsrf($request);

        $site = $this->sites->findForAccount($user->id, (int) $request->post('site_id'));
        if ($site === null) {
            throw HttpException::notFound('That site is not on your account.');
        }

        $this->blocks->unblockSource($site->id, (string) $request->post('source'));

        // Back to the same page of the same filtered list.
        $query = array_filter([
            'q'    => trim((string) $request->post('q')),
            'page' => (int) $request->post('page') > 0 ? (string) (int) $request->post('page') : '',
        ], static fn (string $v): bool => $v !== '');

        return Response::seeOther('/settings/blocks' . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    private function notifyDeleted(Link $link): void
    {
        $site = $this->sites->find($link->siteId);
        if ($site !== null) {
            $this->webHooks->deleted($site, (string) $link->href, (string) $link->targetHref, $link->isPrivate, $link->id);
        }
    }
}
