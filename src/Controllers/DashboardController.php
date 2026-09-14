<?php

declare(strict_types=1);

namespace Webmention\Controllers;

use Webmention\Format\Jf2Format;
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
use Webmention\View\Template;
use Webmention\Webmention\WebHooks;

/**
 * Recent mentions, the review queue, and deleting or blocking.
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

    protected function pendingCount(Account $user): ?int
    {
        return $this->links->countPendingForAccount($user->id);
    }

    /** Waiting mentions shown on the dashboard before the link to the full queue. */
    private const PENDING_PREVIEW = 20;

    /** Waiting mentions per page on /moderation. */
    public const PENDING_PER_PAGE = 50;

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }

        return $this->page('dashboard', 'Dashboard', [
            'pending'       => array_map(self::row(...), $this->links->pendingForAccount($user->id, self::PENDING_PREVIEW)),
            'pending_total' => $this->links->countPendingForAccount($user->id),
            'links'         => array_map(self::row(...), $this->links->recentForAccount($user->id, 40)),
            'notice'        => $request->query('notice'),
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
            'links' => array_map(self::row(...), $this->links->pendingForAccount($user->id, self::PENDING_PER_PAGE, $page * self::PENDING_PER_PAGE)),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
            'csrf'  => $this->session->csrfToken(),
        ], $this->nav($user, 'dashboard'));
    }

    /**
     * Publish a waiting mention (`id`), or every waiting mention from a source
     * domain (`domain`). Publishing sends the web hook it was holding back.
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

        if (($id = $request->post('id')) !== null) {
            $link = $this->links->findForAccount($user->id, (int) $id);
            if ($link !== null && $link->status === 'pending' && !$link->deleted) {
                $this->links->publish($link->id);
                $published[] = $this->links->find($link->id) ?? $link;
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

        return $this->backTo($request, sprintf('%d webmention%s approved.', count($published), count($published) === 1 ? '' : 's'));
    }

    /**
     * Refuse a waiting mention: it is deleted and its source URL blocked, like
     * a delete from the dashboard. No web hook, since it was never announced.
     *
     * @param array<string, string> $params
     */
    public function reject(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }
        $this->checkCsrf($request);

        $link = $this->links->findForAccount($user->id, (int) $request->post('id'));
        if ($link === null || $link->status !== 'pending') {
            return $this->backTo($request, 'That webmention is no longer waiting for review.');
        }

        $this->links->markDeleted($link->id);
        $this->blocks->blockSource($link->siteId, (string) $link->href);

        return $this->backTo($request, 'Webmention rejected; its source URL is blocked.');
    }

    /** Back to the page an action was taken from (dashboard or review queue), with a message. */
    private function backTo(Request $request, string $notice): Response
    {
        $back = (string) $request->post('back');
        $path = preg_match('#^/(dashboard|moderation)(\?page=\d+)?$#', $back) === 1 ? $back : '/dashboard';

        return Response::seeOther($path . (str_contains($path, '?') ? '&' : '?') . 'notice=' . rawurlencode($notice));
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
            $this->webHooks->deleted($site, (string) $link->href, (string) $link->targetHref, $link->isPrivate);
        }
    }

    /** How a mention reads in the dashboard: "liked", "replied to", … */
    public static function kind(?string $type): string
    {
        return match (Jf2Format::relation($type)) {
            'like-of'     => 'liked',
            'repost-of'   => 'reposted',
            'in-reply-to' => 'replied to',
            'bookmark-of' => 'bookmarked',
            'rsvp'        => 'RSVPed ' . substr((string) $type, 5) . ' to',
            default       => 'mentioned',
        };
    }

    /** @return array<string, mixed> What _row.php needs for one mention. */
    public static function row(Link $link): array
    {
        $text    = trim(preg_replace('/\s+/u', ' ', (string) $link->contentText) ?? '');
        $excerpt = $text === '' ? null : (mb_strlen($text) > 200 ? rtrim(mb_substr($text, 0, 200)) . '…' : $text);
        $target  = (string) $link->targetHref;
        $parts   = parse_url(Url::escape($target)) ?: [];
        $path    = ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return [
            'id'          => $link->id,
            'status'      => $link->status,
            'href'        => (string) $link->href,
            'source_url'  => ApiController::safeUrl($link->href),
            'source_host' => (string) (Url::host((string) $link->href) ?? $link->domain),
            'target'      => $target,
            'target_url'  => ApiController::safeUrl($link->targetHref),
            // Shown as host + path, so a row says which site it landed on without the scheme.
            'target_host' => Url::host($target) ?? '',
            'target_path' => Url::host($target) === null ? $target : ($path !== '' ? $path : '/'),
            'author_name' => (string) $link->authorName,
            'author_url'  => Url::blank($link->authorUrl) ? null : ApiController::safeUrl($link->authorUrl),
            'author_host' => Url::blank($link->authorUrl) ? null : Url::host((string) $link->authorUrl),
            'photo'       => Url::blank($link->authorPhoto) ? null : ApiController::safeUrl(Jf2Format::avatarUrl($link->authorPhoto)),
            'type'        => Jf2Format::relation($link->type),
            'kind'        => self::kind($link->type),
            'name'        => Url::blank($link->name) ? null : $link->name,
            'excerpt'     => $excerpt,
            'published'   => Jf2Format::publishedDate($link)?->format('M j, Y'),
            'received'    => $link->createdDate()?->format('M j, Y'),
            'delete_url'  => '/delete?source=' . rawurlencode((string) $link->href) . '&id=' . $link->id,
        ];
    }
}
