<?php

declare(strict_types=1);

namespace Webmention\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use stdClass;
use Webmention\Config;
use Webmention\Format\AtomFormat;
use Webmention\Format\ExampleMentions;
use Webmention\Format\JsonFormat;
use Webmention\Format\Jf2Format;
use Webmention\Format\Url;
use Webmention\Http\HttpException;
use Webmention\Http\JsonResponder;
use Webmention\Http\Request;
use Webmention\Http\Response;
use Webmention\Model\Account;
use Webmention\Model\Link;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\Database;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\LinkSearch;
use Webmention\Storage\PageRepository;
use Webmention\Storage\SiteRepository;
use Webmention\Webmention\RateLimiter;
use Webmention\View\Raw;
use Webmention\View\Template;
use Webmention\Webmention\TargetResolver;

/**
 * The public read API, documented in the README. Port of controllers/api.rb.
 */
final class ApiController extends Controller
{
    /** Lets the HTML feed be embedded and styled, but never run script. */
    private const FEED_CSP = "default-src 'none'; img-src * data:; media-src *; style-src 'self' 'unsafe-inline'; form-action 'none'; base-uri 'none'";

    /** The largest page the API will assemble. */
    public const MAX_PER_PAGE = 1000;

    /** How many target URLs one query may name. */
    public const MAX_TARGETS = 50;

    /** Rows per query while streaming an export. */
    public const EXPORT_BATCH = 1000;

    public function __construct(
        Template $view,
        private readonly JsonResponder $json,
        private readonly Database $db,
        private readonly AccountRepository $accounts,
        private readonly SiteRepository $sites,
        private readonly PageRepository $pages,
        private readonly LinkRepository $links,
        private readonly Config $config,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($view);
    }

    /** @param array<string, string> $params */
    public function count(Request $request, array $params): Response
    {
        $targets = self::targets($request);

        if ($targets === []) {
            return $this->json->respond($request, 400, [
                'error'             => 'invalid_input',
                'error_description' => 'A target URI is required',
            ]);
        }

        $pageIds = $this->pages->idsForHrefs($targets);

        // Raw types are passed through, as they always were, except that plain
        // links and rows with no recorded type both count as "mention", so the
        // breakdown adds up to the total.
        $counts = [];
        foreach ($this->links->typeCountsForPages($pageIds) as $type => $num) {
            $key = $type === 'link' || $type === '' ? 'mention' : (string) $type;
            $counts[$key] = ($counts[$key] ?? 0) + $num;
        }
        ksort($counts);

        $types = new stdClass();
        foreach ($counts as $key => $num) {
            $types->{$key} = $num;
        }

        return $this->json->respond($request, 200, [
            'count' => $this->links->countForPages($pageIds),
            'type'  => $types,
        ]);
    }

    /** @param array<string, string> $params */
    public function mentions(Request $request, array $params): Response
    {
        if (preg_match('/^(links|mentions)(?:\.(json|atom|jf2|html))?\z/', $params['kind'] ?? '', $m) !== 1) {
            throw HttpException::notFound();
        }
        $format = $m[2] ?? 'json';

        $token   = $request->input('token') ?? $request->input('access_token') ?? self::bearerToken($request) ?? '';
        $targets = self::targets($request);

        if ($targets === [] && $token === '') {
            return $this->json->respond($request, 400, [
                'error'             => 'invalid_input',
                'error_description' => 'Either a token or a target URL is required',
            ]);
        }

        $limit = 20;
        if ($request->has('perPage')) {
            $limit = (int) $request->input('perPage');
        } elseif ($request->has('per-page')) {
            $limit = (int) $request->input('per-page');
        }

        $sortDir = $request->input('sort-dir');
        $perPage = min(max(0, $limit), self::MAX_PER_PAGE);
        $page    = max(0, (int) $request->input('page'));

        $properties = $request->inputList('wm-property');

        $filters = [
            'types'        => self::typesForProperties($properties),
            // mention-of is everything the output does not label otherwise (see Jf2Format::LABELLED_TYPES).
            'includeUnlabelled' => in_array('mention-of', $properties, true),
            'createdAfter' => self::parseSince($request->input('since')),
            'idAfter'      => $request->has('since_id') ? (int) $request->input('since_id') : null,
            'sortBy'       => match ($request->input('sort-by')) {
                'rsvp', 'published', 'updated' => (string) $request->input('sort-by'),
                default                        => 'created',
            },
            'descending'   => $sortDir === null || $sortDir === 'down',
            'limit'        => $perPage,
            'offset'       => self::offset($page, $perPage),
        ];

        // Kept from the old app, which set this for matching URLs containing emoji.
        $this->db->pdo()->exec('SET collation_connection = "utf8mb4_general_ci"');

        $account = null;

        if ($targets === []) {
            $account = $this->accounts->findByToken($token);

            if ($account === null) {
                return $this->json->respond($request, 401, [
                    'error'             => 'forbidden',
                    'error_description' => 'Access token was not valid',
                ]);
            }

            $siteId = null;
            if ($request->has('domain')) {
                $site = $this->sites->findByAccountAndDomain($account->id, (string) $request->input('domain'));
                if ($site === null) {
                    return $this->render($request, $format, [], $account, self::paging($perPage, $page, 0));
                }
                $siteId = $site->id;
            }

            // The owner may see the private webmentions sent to their own sites.
            $search = new LinkSearch(...[...$filters, 'accountId' => $account->id, 'siteId' => $siteId, 'includePrivate' => true]);
        } else {
            // A single target with no scheme (e.g. "//example.com/post") matches either scheme.
            if (!is_array($request->post['target'] ?? $request->query['target'] ?? null)
                && parse_url(Url::escape($targets[0]), PHP_URL_SCHEME) === null) {
                $targets = ['https:' . $targets[0], 'http:' . $targets[0]];
            }

            $search = new LinkSearch(...[...$filters, 'pageIds' => $this->pages->idsForHrefs($targets)]);
        }

        return $this->render($request, $format, $this->links->search($search), $account, self::paging($perPage, $page, $this->links->count($search)));
    }

    /**
     * The paging block on JSON responses (issue 108): what page this is and
     * how many there are, so a client knows whether to ask for another.
     *
     * @return array{per-page: int, page: int, total: int, total-pages: int}
     */
    public static function paging(int $perPage, int $page, int $total): array
    {
        return [
            'per-page'    => $perPage,
            'page'        => $page,
            'total'       => $total,
            'total-pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    /**
     * Everything on an account as one jf2 feed, streamed oldest first, for
     * backups and moving elsewhere (issue 109). Private mentions are
     * included; held, hidden and deleted ones are not.
     *
     * @param array<string, string> $params
     */
    public function export(Request $request, array $params): Response
    {
        $token   = $request->input('token') ?? $request->input('access_token') ?? self::bearerToken($request) ?? '';
        $account = $token === '' ? null : $this->accounts->findByToken($token);
        if ($account === null) {
            return $this->json->respond($request, 401, ['error' => 'forbidden', 'error_description' => 'Access token was not valid']);
        }

        $site = null;
        if ($request->has('domain')) {
            $site = $this->sites->findByAccountAndDomain($account->id, (string) $request->input('domain'));
            if ($site === null) {
                return $this->json->respond($request, 404, ['error' => 'not_found', 'error_description' => 'That domain is not on this account']);
            }
        }

        // A full export reads every row on the account; one at a time is plenty.
        if (!$this->limiter->allow('export', (string) $account->id, 1, 300)) {
            return $this->json->respond($request, 429, [
                'error'             => 'rate_limit_exceeded',
                'error_description' => 'An export was started for this account in the last five minutes; try again later',
            ], ['retry-after' => '300']);
        }

        $accountId = $account->id;
        $siteId    = $site?->id;
        $links     = $this->links;

        // One record per line: JSON allows whitespace between values, and a
        // file this size is far easier to grep, diff or stream line by line.
        $writer = static function () use ($accountId, $siteId, $links): void {
            echo '{"type":"feed","name":"Webmentions","children":[', "\n";
            $after = 0;
            $first = true;
            do {
                $batch = $links->exportBatch($accountId, $siteId, $after, self::EXPORT_BATCH);
                foreach ($batch as $link) {
                    echo $first ? '' : ",\n", JsonResponder::encode(Jf2Format::entry($link));
                    $first = false;
                    $after = $link->id;
                }
                if (function_exists('flush')) {
                    flush();
                }
            } while (count($batch) === self::EXPORT_BATCH);
            echo "\n]}\n";
        };

        $name = preg_replace('/[^A-Za-z0-9.\-]+/', '-', (string) ($site?->domain ?? $account->domain ?? 'account'));

        return Response::stream($writer, [
            'content-type'        => 'application/json;charset=UTF-8',
            'cache-control'       => 'no-store',
            'content-disposition' => 'attachment; filename="webmentions-' . $name . '-' . gmdate('Y-m-d') . '.jf2.json"',
            // Let nginx pass each batch on as it is written rather than
            // holding the whole file first.
            'x-accel-buffering'   => 'no',
        ]);
    }

    /**
     * Which mentions were deleted, so a client can prune its cache (issue
     * 128): by target, or everything on the account with a token. `since`
     * and `since_id` refer to the deletion, newest first.
     *
     * @param array<string, string> $params
     */
    public function deleted(Request $request, array $params): Response
    {
        $token   = $request->input('token') ?? $request->input('access_token') ?? self::bearerToken($request) ?? '';
        $targets = self::targets($request);

        if ($targets === [] && $token === '') {
            return $this->json->respond($request, 400, [
                'error'             => 'invalid_input',
                'error_description' => 'Either a token or a target URL is required',
            ]);
        }

        $limit = 20;
        if ($request->has('perPage')) {
            $limit = (int) $request->input('perPage');
        } elseif ($request->has('per-page')) {
            $limit = (int) $request->input('per-page');
        }
        $limit = min(max(0, $limit), self::MAX_PER_PAGE);

        $filters = [
            'createdAfter' => self::parseSince($request->input('since')),
            'idAfter'      => $request->has('since_id') ? (int) $request->input('since_id') : null,
            'limit'        => $limit,
            'offset'       => self::offset((int) $request->input('page'), $limit),
        ];

        if ($targets === []) {
            $account = $this->accounts->findByToken($token);
            if ($account === null) {
                return $this->json->respond($request, 401, ['error' => 'forbidden', 'error_description' => 'Access token was not valid']);
            }
            $links = $this->links->searchDeleted(new LinkSearch(...[...$filters, 'accountId' => $account->id, 'includePrivate' => true]));
        } else {
            $links = $this->links->searchDeleted(new LinkSearch(...[...$filters, 'pageIds' => $this->pages->idsForHrefs($targets)]));
        }

        return $this->json->respond($request, 200, [
            'type'     => 'feed',
            'name'     => 'Deleted webmentions',
            'children' => array_map(static fn (Link $l): array => [
                'wm-id'      => $l->id,
                'wm-source'  => $l->href,
                'wm-target'  => $l->targetHref,
                'wm-deleted' => $l->updatedDate()?->format('Y-m-d\TH:i:s\Z'),
            ], $links),
        ]);
    }

    /**
     * @param list<Link>                                                    $links
     * @param array{per-page: int, page: int, total: int, total-pages: int} $paging
     */
    private function render(Request $request, string $format, array $links, ?Account $account, array $paging): Response
    {
        return match ($format) {
            'jf2'  => $this->json->respond($request, 200, [...Jf2Format::feed($links), 'paging' => $paging]),
            // Feeds fetched with a token must not be kept by a shared cache.
            'atom' => Response::make(200, AtomFormat::feed($links, $this->config->baseUrl()), [
                'content-type'                => 'application/atom+xml;charset=UTF-8',
                'access-control-allow-origin' => '*',
                'cache-control'               => 'no-store',
            ]),
            'html' => Response::html($this->view->render('mentions', [
                'account' => $account?->username,
                'links'   => array_map(self::feedEntry(...), $links),
            ]))->withHeader('content-security-policy', self::FEED_CSP)->withHeader('cache-control', 'no-store'),
            default => $this->json->respond($request, 200, [...JsonFormat::links($links), 'paging' => $paging]),
        };
    }

    /**
     * Sample data for client developers (issue 77): every shape the jf2 feed
     * can take, as fake sites and made-up people. Accepts the same target,
     * wm-property, sort-dir, per-page and page parameters as the real feed,
     * plus seed to get the same names twice.
     *
     * @param array<string, string> $params
     */
    public function exampleMentions(Request $request, array $params): Response
    {
        $links = $this->exampleLinks($request);

        $properties = $request->inputList('wm-property');
        if ($properties !== []) {
            $links = array_values(array_filter($links, static fn (Link $l): bool => in_array(Jf2Format::relation($l->type), $properties, true)));
        }

        // Newest first, as the real feed's default.
        usort($links, static fn (Link $a, Link $b): int => strcmp((string) $b->createdAt, (string) $a->createdAt) ?: $b->id <=> $a->id);
        if ($request->input('sort-dir') === 'up') {
            $links = array_reverse($links);
        }

        $limit = 20;
        if ($request->has('perPage')) {
            $limit = (int) $request->input('perPage');
        } elseif ($request->has('per-page')) {
            $limit = (int) $request->input('per-page');
        }
        $limit = min(max(0, $limit), self::MAX_PER_PAGE);
        $page  = max(0, (int) $request->input('page'));
        $total = count($links);
        $links = array_slice($links, self::offset($page, $limit), $limit);

        return $this->json->respond($request, 200, [...Jf2Format::feed($links), 'paging' => self::paging($limit, $page, $total)]);
    }

    /** The count endpoint's answer for the sample data. @param array<string, string> $params */
    public function exampleCount(Request $request, array $params): Response
    {
        $links  = $this->exampleLinks($request);
        $counts = [];
        foreach ($links as $link) {
            $key          = $link->type === 'link' || $link->type === null ? 'mention' : $link->type;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);

        $types = new stdClass();
        foreach ($counts as $key => $num) {
            $types->{$key} = $num;
        }

        return $this->json->respond($request, 200, ['count' => count($links), 'type' => $types]);
    }

    /** @return list<Link> */
    private function exampleLinks(Request $request): array
    {
        $target = (string) $request->input('target');
        if (self::safeUrl($target) === null || strlen($target) > WebmentionController::MAX_URL_BYTES) {
            $target = 'https://example.com/post';
        }

        $seed = $request->input('seed');
        $seed = $seed !== null && $seed !== '' && preg_match('/^\d{1,9}$/', $seed) === 1 ? (int) $seed : random_int(1, 999999999);

        return ExampleMentions::links($target, $seed, $this->config->baseUrl());
    }

    /**
     * wm-property values (in-reply-to, like-of, rsvp, …) as stored link types.
     *
     * @param  list<string> $properties
     * @return list<string>
     */
    public static function typesForProperties(array $properties): array
    {
        $types = [];

        foreach ($properties as $property) {
            if ($property === 'rsvp') {
                array_push($types, 'rsvp-yes', 'rsvp-no', 'rsvp-maybe', 'rsvp-interested');
            } elseif ($property === 'mention-of') {
                $types[] = 'link';
            } else {
                $types[] = (string) preg_replace(['/^in-/', '/-(to|of)$/'], '', $property);
            }
        }

        return $types;
    }

    /**
     * Target URLs from the query, without any too long to have been stored
     * (see WebmentionController::MAX_URL_BYTES) and at most MAX_TARGETS of
     * them. A #fragment never names a different page, so it is dropped;
     * PageRepository::idsForHrefs() also matches aliases.
     *
     * @return list<string>
     */
    private static function targets(Request $request): array
    {
        $targets = array_values(array_filter(
            array_map(TargetResolver::key(...), $request->inputList('target')),
            static fn (string $target): bool => $target !== '' && strlen($target) <= WebmentionController::MAX_URL_BYTES,
        ));

        return array_slice(array_values(array_unique($targets)), 0, self::MAX_TARGETS);
    }

    /** The token from an `Authorization: Bearer` header, so it can stay out of URLs and access logs. */
    public static function bearerToken(Request $request): ?string
    {
        $header = (string) $request->header('authorization');

        return preg_match('/^Bearer\s+(\S+)\s*\z/i', $header, $m) === 1 ? $m[1] : null;
    }

    /** The row offset for a page, without overflowing on an absurd page number. */
    public static function offset(int $page, int $limit): int
    {
        $page  = max(0, $page);
        $limit = max(0, $limit);

        return $limit > 0 && $page > intdiv(PHP_INT_MAX, $limit) ? PHP_INT_MAX : $page * $limit;
    }

    /** A `since` timestamp in any parseable format, as a UTC DATETIME string. */
    public static function parseSince(?string $since): ?string
    {
        if ($since === null || trim($since) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($since, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (Exception) {
            return null;
        }
    }

    /** @return array<string, mixed> What the h-feed template needs for one link. */
    public static function feedEntry(Link $link): array
    {
        $date = Jf2Format::publishedDate($link) ?? $link->createdDate();

        return [
            'target'         => (string) $link->targetHref,
            'relation_class' => $link->mf2RelationClass(),
            'has_author'     => $link->hasAuthorInfo(),
            'author_photo'   => Url::blank($link->authorPhoto) ? null : self::safeUrl(Jf2Format::avatarUrl($link->authorPhoto)),
            'author_url'     => Url::blank($link->authorUrl) ? null : self::safeUrl($link->authorUrl),
            'author_name'    => (string) $link->authorName,
            'name'           => Url::blank($link->name) ? null : $link->name,
            'content_html'   => Url::blank($link->content) ? null : new Raw((string) $link->content),
            'content_text'   => Url::blank($link->contentText) ? null : $link->contentText,
            'datetime'       => $date?->format('Y-m-d\TH:i:sO'),
            'date_label'     => $date?->format('M j, Y g:ia P'),
            'url'            => self::safeUrl($link->url ?? $link->href),
        ];
    }

    /** Only http(s) URLs are put into href/src attributes. */
    public static function safeUrl(?string $url): ?string
    {
        return $url !== null && preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }
}
