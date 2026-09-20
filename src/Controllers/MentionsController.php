<?php

declare(strict_types=1);

namespace Webmention\Controllers;

use DateTimeImmutable;
use Webmention\Format\Url;
use Webmention\Http\Request;
use Webmention\Http\Response;
use Webmention\Http\Session;
use Webmention\Model\Account;
use Webmention\Model\Link;
use Webmention\Model\Site;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\BlockRepository;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\LinkSearch;
use Webmention\Storage\MuteRepository;
use Webmention\Storage\SiteRepository;
use Webmention\View\MentionRow;
use Webmention\View\Template;
use Webmention\Webmention\SourceActivity;

/**
 * Every mention on the account, paged and filtered: by site, by kind, by
 * source domain, and by state (published, awaiting review, hidden by a mute
 * rule, deleted). And the source domains behind them, busiest first.
 */
final class MentionsController extends Controller
{
    use RequiresLogin;

    public const PER_PAGE = 50;

    /** Filter value => the link types it covers ("mention" is everything else; see Jf2Format::LABELLED_TYPES). */
    public const TYPES = [
        'reply'    => ['reply'],
        'like'     => ['like'],
        'repost'   => ['repost'],
        'bookmark' => ['bookmark'],
        'rsvp'     => ['rsvp-yes', 'rsvp-no', 'rsvp-maybe', 'rsvp-interested'],
        'mention'  => [],
    ];

    public function __construct(
        Template $view,
        private readonly Session $session,
        private readonly AccountRepository $accounts,
        private readonly SiteRepository $sites,
        private readonly LinkRepository $links,
        private readonly MuteRepository $mutes,
        private readonly BlockRepository $blocks,
        private readonly SourceActivity $sources,
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

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }

        $sites  = $this->sites->listForAccount($user->id);
        $byId   = [];
        foreach ($sites as $site) {
            $byId[$site->id] = $site;
        }

        $status = (string) $request->query('status');
        if (!in_array($status, LinkSearch::STATUSES, true)) {
            $status = LinkSearch::PUBLISHED;
        }
        $siteId = (int) $request->query('site');
        $site   = $byId[$siteId] ?? null;
        $type   = (string) $request->query('type');
        if (!isset(self::TYPES[$type])) {
            $type = '';
        }
        $domain = self::domain($request->query('domain'));

        $filters = [
            'accountId'         => $user->id,
            'siteId'            => $site?->id,
            'types'             => self::TYPES[$type] ?? [],
            'includeUnlabelled' => $type === 'mention',
            'sourceDomain'      => $domain,
            'status'            => $status,
            // Deleted ones read best most recently deleted first.
            'sortBy'            => $status === LinkSearch::DELETED ? 'updated' : 'created',
            'includePrivate'    => true,
        ];

        $total = $this->links->count(new LinkSearch(...$filters));
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min(max(0, (int) $request->query('page')), $pages - 1);

        $links = $this->links->search(new LinkSearch(...[...$filters, 'limit' => self::PER_PAGE, 'offset' => $page * self::PER_PAGE]));
        $rows  = array_map(MentionRow::row(...), $links);

        if ($status === LinkSearch::HIDDEN) {
            $rules = $this->mutes->forAccount($user->id);
            foreach ($links as $i => $link) {
                foreach ($rules as $rule) {
                    if ($rule->matches($link->href, $link->authorUrl)) {
                        $rows[$i]['rule'] = $rule->describe();
                        break;
                    }
                }
            }
        }

        $query = array_filter([
            'status' => $status === LinkSearch::PUBLISHED ? null : $status,
            'site'   => $site?->id,
            'type'   => $type === '' ? null : $type,
            'domain' => $domain,
        ], static fn ($v): bool => $v !== null && $v !== '');

        return $this->page('browse', 'Mentions', [
            'links'  => $rows,
            'total'  => $total,
            'page'   => $page,
            'pages'  => $pages,
            'status' => $status,
            'site'   => $site?->id,
            'sites'  => array_map(static fn (Site $s): array => ['id' => $s->id, 'domain' => $s->domain, 'archived' => $s->isArchived()], $sites),
            'type'   => $type,
            'types'  => array_keys(self::TYPES),
            'domain' => $domain ?? '',
            'query'  => $query,
            'back'   => '/mentions' . ($query === [] && $page === 0 ? '' : '?' . http_build_query([...$query, 'page' => $page > 0 ? $page : null])),
            'notice' => $this->session->takeFlash('notice'),
            'csrf'   => $this->session->csrfToken(),
        ], $this->nav($user, 'mentions'));
    }

    /**
     * The source domains of the last SourceActivity::DAYS days, with what
     * the owner has already done about each.
     *
     * @param array<string, string> $params
     */
    public function sources(Request $request, array $params): Response
    {
        if (($user = $this->currentUser($request)) === null) {
            return Response::redirect('/');
        }

        $blocked = array_fill_keys($this->blocks->domainsForAccount($user->id), true);
        $rules   = $this->mutes->forAccount($user->id);

        $rows = [];
        foreach ($this->sources->recent($user->id) as $row) {
            $muted = null;
            foreach ($rules as $rule) {
                // A source rule on the domain, or on a URL prefix at it; author rules depend on each mention.
                if ($rule->kind === 'source' && $rule->matches('https://' . $row['domain'] . '/', null)) {
                    $muted = $rule->describe();
                    break;
                }
            }
            $rows[] = [
                ...$row,
                'last_seen'  => (new DateTimeImmutable($row['last_seen']))->format('M j, Y'),
                'blocked'    => isset($blocked[$row['domain']]),
                'muted'      => $muted,
                'browse_url' => '/mentions?' . http_build_query(['domain' => $row['domain']]),
                'review_url' => '/mentions?' . http_build_query(['status' => 'pending', 'domain' => $row['domain']]),
                'block_url'  => '/delete?' . http_build_query(['domain' => $row['domain'], 'back' => '/sources']),
            ];
        }

        return $this->page('sources', 'Sources', [
            'sources' => $rows,
            'days'    => SourceActivity::DAYS,
            'limit'   => SourceActivity::LIMIT,
            'notice'  => $this->session->takeFlash('notice'),
            'csrf'    => $this->session->csrfToken(),
        ], $this->nav($user, 'sources'));
    }

    /** A hostname from what was typed: "Example.com", "https://example.com/x" and " example.com " all give "example.com". */
    public static function domain(?string $input): ?string
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }
        $host = str_contains($input, '://') ? Url::host($input) : strtolower(explode('/', $input)[0]);

        return $host !== null && preg_match('/^[a-z0-9.\-_:]+$/', $host) === 1 ? $host : null;
    }
}
