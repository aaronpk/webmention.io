<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use Webmention\Format\Url;
use Webmention\Logging\Log;
use Webmention\Model\Page;
use Webmention\Model\Site;
use Webmention\Storage\PageRepository;
use Webmention\Storage\SiteRepository;

/**
 * Decides which page a target URL belongs to.
 *
 * A mention is filed under the target's canonical URL: the fragment is
 * dropped, and on first sight the target is fetched so the site's own
 * redirects and its rel=canonical can say which URL the page really lives
 * at. Every other form that led there is kept as an alias, so later mentions
 * and API queries for it find the same page without another fetch.
 *
 * Nothing here second-guesses a site: trailing slashes and schemes count as
 * different pages unless the site redirects one to the other. A canonical
 * URL is only honoured when it is on one of the account's own sites.
 *
 * Verification is unaffected: the source must still contain the target
 * exactly as the sender gave it.
 */
final class TargetResolver
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly SiteRepository $sites,
        private readonly SourceFetcher $fetcher,
        private readonly Log $log,
    ) {
    }

    /** The URL without its #fragment, which never names a different page. */
    public static function key(string $url): string
    {
        $hash = strpos($url, '#');

        return $hash === false ? $url : substr($url, 0, $hash);
    }

    /**
     * The "#fragment" of a URL without the hash, or null when it has none or
     * it is empty. Capped at the column's width.
     */
    public static function fragment(string $url): ?string
    {
        $hash = strpos($url, '#');
        if ($hash === false) {
            return null;
        }

        $fragment = substr($url, $hash + 1);

        return $fragment === '' ? null : mb_substr($fragment, 0, 255);
    }

    /** The page already filed for this target, by its URL or an alias, without fetching. */
    public function existingPageFor(Site $site, string $target): ?Page
    {
        $key = self::key($target);

        $page = $this->pages->findBySiteAndHref($site->id, $key) ?? $this->pages->findByAlias($site->id, $key);

        // Pages filed under a fragment URL before fragments were dropped.
        if ($page === null && $key !== $target) {
            $page = $this->pages->findBySiteAndHref($site->id, $target) ?? $this->pages->findByAlias($site->id, $target);
        }

        return $page;
    }

    /** The page for this target, fetching it and creating the page on first sight. */
    public function pageFor(Site $site, string $target): Page
    {
        $page = $this->existingPageFor($site, $target);
        if ($page !== null) {
            return $page;
        }

        $key     = self::key($target);
        $fetched = $this->fetch($key);

        $canonical     = $key;
        $canonicalSite = $site;

        if ($fetched['canonical'] !== null && $fetched['canonical'] !== $key) {
            $owner = $this->siteFor($site, $fetched['canonical']);
            if ($owner !== null) {
                $canonical     = $fetched['canonical'];
                $canonicalSite = $owner;
            } else {
                $this->log->info("Canonical URL {$fetched['canonical']} for $key is not on the account; filing under the target as given");
            }
        }

        $page = $this->pages->findBySiteAndHref($canonicalSite->id, $canonical) ?? $this->pages->findByAlias($canonicalSite->id, $canonical);

        if ($page === null) {
            $page = $this->pages->create($canonicalSite->accountId, $canonicalSite->id, $canonical);
            if ($fetched['type'] !== null || $fetched['name'] !== null) {
                $this->pages->describe($page->id, $fetched['type'], $fetched['name']);
                $page = $this->pages->find($page->id) ?? $page;
            }
        }

        if ($canonical !== $key) {
            $this->pages->addAlias($site->id, $key, $page->id);
            $this->log->info("Filed $key under $canonical");
        }

        return $page;
    }

    /**
     * Where a URL on one of the account's sites really lives, by fetching it:
     * the URL after redirects and rel=canonical, and the site that owns it.
     * Null when it could not be fetched or leads off the account.
     *
     * @return array{url: string, site: Site}|null
     */
    public function canonicalFor(Site $site, string $url): ?array
    {
        $fetched = $this->fetch(self::key($url));
        if ($fetched['canonical'] === null) {
            return null;
        }

        $owner = $this->siteFor($site, $fetched['canonical']);

        return $owner === null ? null : ['url' => $fetched['canonical'], 'site' => $owner];
    }

    /** The account's site that a URL is on, if any. */
    private function siteFor(Site $site, string $url): ?Site
    {
        $host = Url::host($url);
        if ($host === null || !Url::isHttp($url)) {
            return null;
        }

        if (strtolower((string) $site->domain) === $host) {
            return $site;
        }

        $owner = $this->sites->findByAccountAndDomain($site->accountId, $host);
        if ($owner !== null && $owner->isArchived()) {
            // An archived site takes no new webmentions, not even by canonical URL.
            $owner = null;
        }
        if ($owner === null && Url::sameOwner($host, (string) $site->domain)) {
            // example.com and www.example.com are one site's two names.
            $owner = $site;
        }

        return $owner;
    }

    /**
     * Fetch a target and report its canonical URL and what kind of post it is.
     *
     * @return array{canonical: string|null, type: string|null, name: string|null}
     */
    private function fetch(string $url): array
    {
        $none   = ['canonical' => null, 'type' => null, 'name' => null];
        $parsed = $this->fetcher->parse($url);

        if (isset($parsed['error'])) {
            $this->log->info("Error retrieving page $url: {$parsed['error']}");

            return $none;
        }

        $data      = $parsed['data'] ?? [];
        $canonical = self::key((string) ($parsed['final_url'] ?? $url));

        $declared = $data['rels']['canonical'] ?? null;
        if (is_string($declared) && Url::isHttp($declared)) {
            $canonical = self::key($declared);
        }

        $type = null;
        if (($data['type'] ?? null) === 'entry') {
            $type = match (true) {
                !empty($data['photo']) => 'photo',
                !empty($data['video']) => 'video',
                !empty($data['audio']) => 'audio',
                default                => 'entry',
            };
        } elseif (($data['type'] ?? null) === 'event') {
            $type = 'event';
        }

        return [
            'canonical' => $canonical,
            'type'      => $type,
            'name'      => isset($data['name']) && is_string($data['name']) ? $data['name'] : null,
        ];
    }
}
