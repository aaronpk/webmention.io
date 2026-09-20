<?php

declare(strict_types=1);

namespace Webmention\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Webmention\Format\Url;
use Webmention\Model\Site;
use Webmention\Storage\AccountRepository;
use Webmention\Storage\LinkRepository;
use Webmention\Storage\PageRepository;
use Webmention\Storage\SiteRepository;
use Webmention\Webmention\StatusStore;

/**
 * One box that takes whatever a support email happens to contain: a status
 * receipt, a source URL, a target URL, a domain, an account name, or a bare
 * id. Whatever matches is reported; a query may well match in more than one
 * way, and seeing all of them is the point.
 *
 * Every path here is chosen to land on an index. links.href has none of its
 * own, so a source URL is always looked up alongside its host, which does
 * (`domain`); a target URL is resolved through its site rather than by
 * scanning pages.
 */
final class Lookup
{
    public const LIMIT = 25;

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly SiteRepository $sites,
        private readonly PageRepository $pages,
        private readonly LinkRepository $links,
        private readonly StatusStore $status,
    ) {
    }

    /**
     * @return array{query: string, kind: string, sections: list<array<string, mixed>>}
     */
    public function find(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['query' => '', 'kind' => 'empty', 'sections' => []];
        }

        $sections = [];

        if (preg_match('/^[A-Za-z0-9]{16,20}$/', $query) === 1) {
            $sections = [...$sections, ...$this->byReceipt($query)];
        }

        if (ctype_digit($query)) {
            $sections = [...$sections, ...$this->byId((int) $query)];
        }

        if (Url::isHttp($query)) {
            $sections = [...$sections, ...$this->bySource($query), ...$this->byTarget($query)];
        }

        $domain = self::domain($query);
        if ($domain !== null) {
            $sections = [...$sections, ...$this->byDomain($domain)];
        } elseif (!Url::isHttp($query) && !ctype_digit($query)) {
            $sections = [...$sections, ...$this->byName($query)];
        }

        return [
            'query'    => $query,
            'kind'     => $sections === [] ? 'nothing' : 'found',
            'sections' => $sections,
        ];
    }

    /** The status document a receipt points at, plus the mention it belongs to. */
    private function byReceipt(string $token): array
    {
        $out = [];

        $document = $this->status->get($token);
        if ($document !== null) {
            $out[] = [
                'kind'  => 'receipt',
                'title' => "Status receipt $token",
                'note'  => 'Kept in Redis for three days after the webmention was received.',
                'json'  => json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            ];
        }

        $link = $this->links->findByToken($token);
        if ($link !== null) {
            $out[] = [
                'kind'     => 'mentions',
                'title'    => 'The webmention that receipt was issued for',
                'mentions' => MentionFacts::all([$link]),
            ];
        }

        if ($out === [] ) {
            $out[] = [
                'kind'  => 'note',
                'title' => "No status for receipt $token",
                'note'  => 'A receipt is kept for three days. After that only the webmention itself remains, and only if it was stored.',
            ];
        }

        return $out;
    }

    /** A bare number could be any of the three ids, so try all three. */
    private function byId(int $id): array
    {
        $out = [];

        $link = $this->links->find($id);
        if ($link !== null) {
            $out[] = ['kind' => 'mentions', 'title' => "Webmention $id", 'mentions' => MentionFacts::all([$link])];
        }

        $account = $this->accounts->find($id);
        if ($account !== null) {
            $out[] = [
                'kind'    => 'accounts',
                'title'   => "Account $id",
                'accounts' => [self::accountRow($account->id, $account->username, $account->domain)],
            ];
        }

        $site = $this->sites->find($id);
        if ($site !== null) {
            $out[] = ['kind' => 'sites', 'title' => "Site $id", 'sites' => [$this->siteRow($site)]];
        }

        return $out;
    }

    /** Everything sent from one URL, whoever received it. */
    private function bySource(string $url): array
    {
        $host = Url::host($url);
        if ($host === null) {
            return [];
        }

        $mentions = $this->links->fromSource($host, $url, self::LIMIT);

        return $mentions === [] ? [] : [[
            'kind'     => 'mentions',
            'title'    => 'Sent from this URL',
            'note'     => 'Every account that received it, deleted and held rows included.',
            'mentions' => MentionFacts::all($mentions),
        ]];
    }

    /** The page a URL is filed under, and what is filed there. */
    private function byTarget(string $url): array
    {
        $host = Url::host($url);
        if ($host === null) {
            return [];
        }

        $out = [];
        foreach ($this->sites->allForDomain($host) as $site) {
            $page  = $this->pages->findBySiteAndHref($site->id, $url);
            $alias = false;
            if ($page === null) {
                $page  = $this->pages->findByAlias($site->id, $url);
                $alias = $page !== null;
            }
            if ($page === null) {
                continue;
            }

            $account = $this->accounts->find($site->accountId);
            $out[]   = [
                'kind'     => 'mentions',
                'title'    => 'Received at this URL',
                'note'     => sprintf(
                    'Page %d on site %d (%s), account %s.%s',
                    $page->id,
                    $site->id,
                    (string) $site->domain,
                    $account?->username ?? $account?->domain ?? (string) $site->accountId,
                    $alias ? ' The URL you typed is an alias; mentions are filed under ' . $page->href . '.' : '',
                ),
                'account_id' => $site->accountId,
                'mentions'   => MentionFacts::all($this->links->anyForPage($page->id, self::LIMIT)),
            ];
        }

        return $out;
    }

    /** Who holds a domain, and what has been arriving from it. */
    private function byDomain(string $domain): array
    {
        $out = [];

        $account = $this->accounts->findByName($domain);
        if ($account !== null) {
            $out[] = [
                'kind'     => 'accounts',
                'title'    => "Account named $domain",
                'accounts' => [self::accountRow($account->id, $account->username, $account->domain)],
            ];
        }

        $sites = $this->sites->allForDomain($domain);
        if ($sites !== []) {
            $out[] = [
                'kind'  => 'sites',
                'title' => count($sites) === 1 ? "The site for $domain" : count($sites) . " sites for $domain",
                'note'  => count($sites) > 1 ? 'More than one account holds this domain. A verified row on two accounts usually means someone signed in with a domain they had already added elsewhere.' : null,
                'sites' => array_map($this->siteRow(...), $sites),
            ];
        }

        $since  = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-30 days')->format('Y-m-d H:i:s');
        $sending = $this->links->sourceDomainEverywhereSince($domain, $since);
        if ($sending !== null) {
            $out[] = ['kind' => 'sending', 'title' => "What $domain has sent in the last 30 days", 'sending' => $sending];
        }

        return $out;
    }

    /** An account named by a username rather than a domain. */
    private function byName(string $name): array
    {
        $account = $this->accounts->findByName(strtolower($name));

        return $account === null ? [] : [[
            'kind'     => 'accounts',
            'title'    => "Account named $name",
            'accounts' => [self::accountRow($account->id, $account->username, $account->domain)],
        ]];
    }

    /** @return array<string, mixed> */
    private function siteRow(Site $site): array
    {
        $account = $this->accounts->find($site->accountId);

        return [
            'id'          => $site->id,
            'domain'      => $site->domain,
            'account_id'  => $site->accountId,
            'account'     => $account?->username ?? $account?->domain ?? ('account ' . $site->accountId),
            'verified_at' => $site->verifiedAt,
            'checked_at'  => $site->verificationCheckedAt,
            'error'       => $site->verificationError,
            'archived_at' => $site->archivedAt,
            'created_at'  => $site->createdAt,
        ];
    }

    /** @return array<string, mixed> */
    private static function accountRow(int $id, ?string $username, ?string $domain): array
    {
        return ['id' => $id, 'username' => $username, 'domain' => $domain];
    }

    /** A bare domain as typed: no scheme, no path, at least one dot. */
    public static function domain(string $input): ?string
    {
        $input = strtolower(trim($input));

        return preg_match('/^[a-z0-9][a-z0-9.\-]*\.[a-z]{2,}$/', $input) === 1 ? $input : null;
    }
}
