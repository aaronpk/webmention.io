<?php

declare(strict_types=1);

namespace Webmention\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use stdClass;
use Webmention\Config;
use Webmention\Format\AtomFormat;
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
use Webmention\View\Raw;
use Webmention\View\Template;

/**
 * The public read API, documented in the README. Port of controllers/api.rb.
 */
final class ApiController extends Controller
{
    /** Lets the HTML feed be embedded and styled, but never run script. */
    private const FEED_CSP = "default-src 'none'; img-src * data:; media-src *; style-src 'self' 'unsafe-inline'; base-uri 'none'";

    public function __construct(
        Template $view,
        private readonly JsonResponder $json,
        private readonly Database $db,
        private readonly AccountRepository $accounts,
        private readonly SiteRepository $sites,
        private readonly PageRepository $pages,
        private readonly LinkRepository $links,
        private readonly Config $config,
    ) {
        parent::__construct($view);
    }

    /** @param array<string, string> $params */
    public function count(Request $request, array $params): Response
    {
        $targets = $request->inputList('target');

        if ($targets === []) {
            return $this->json->respond($request, 400, [
                'error'             => 'invalid_input',
                'error_description' => 'A target URI is required',
            ]);
        }

        $pageIds = $this->pages->idsForHrefs($targets);

        $types = new stdClass();
        foreach ($this->links->typeCountsForPages($pageIds) as $type => $num) {
            $types->{$type === 'link' ? 'mention' : $type} = $num;
        }

        return $this->json->respond($request, 200, [
            'count' => $this->links->countForPages($pageIds),
            'type'  => $types,
        ]);
    }

    /** @param array<string, string> $params */
    public function mentions(Request $request, array $params): Response
    {
        if (preg_match('/^(links|mentions)(?:\.(json|atom|jf2|html))?$/', $params['kind'] ?? '', $m) !== 1) {
            throw HttpException::notFound();
        }
        $format = $m[2] ?? 'json';

        $token   = $request->input('token') ?? $request->input('access_token') ?? '';
        $targets = $request->inputList('target');

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

        $filters = [
            'types'        => self::typesForProperties($request->inputList('wm-property')),
            'createdAfter' => self::parseSince($request->input('since')),
            'idAfter'      => $request->has('since_id') ? (int) $request->input('since_id') : null,
            'sortBy'       => match ($request->input('sort-by')) {
                'rsvp', 'published', 'updated' => (string) $request->input('sort-by'),
                default                        => 'created',
            },
            'descending'   => $sortDir === null || $sortDir === 'down',
            'limit'        => max(0, $limit),
            'offset'       => max(0, (int) $request->input('page')) * max(0, $limit),
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
                    return $this->render($request, $format, [], $account);
                }
                $siteId = $site->id;
            }

            $links = $this->links->search(new LinkSearch(...[...$filters, 'accountId' => $account->id, 'siteId' => $siteId]));
        } else {
            // A single target with no scheme (e.g. "//example.com/post") matches either scheme.
            if (!is_array($request->post['target'] ?? $request->query['target'] ?? null)
                && parse_url(Url::escape($targets[0]), PHP_URL_SCHEME) === null) {
                $targets = ['https:' . $targets[0], 'http:' . $targets[0]];
            }

            $links = $this->links->search(new LinkSearch(...[...$filters, 'pageIds' => $this->pages->idsForHrefs($targets)]));
        }

        return $this->render($request, $format, $links, $account);
    }

    /** @param list<Link> $links */
    private function render(Request $request, string $format, array $links, ?Account $account): Response
    {
        return match ($format) {
            'jf2'  => $this->json->respond($request, 200, Jf2Format::feed($links)),
            'atom' => Response::make(200, AtomFormat::feed($links, $this->config->baseUrl()), [
                'content-type'                => 'application/atom+xml;charset=UTF-8',
                'access-control-allow-origin' => '*',
            ]),
            'html' => Response::html($this->view->render('mentions', [
                'account' => $account?->username,
                'links'   => array_map(self::feedEntry(...), $links),
            ]))->withHeader('content-security-policy', self::FEED_CSP),
            default => $this->json->respond($request, 200, JsonFormat::links($links)),
        };
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
