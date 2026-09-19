<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use Webmention\Config;
use Webmention\Format\Url;
use Webmention\Model\Account;

/**
 * Proof that an account may receive webmentions for a domain: a page served
 * by the domain advertises one of the account's endpoints as its webmention
 * endpoint, in a Link header or a <link>/<a rel="webmention">.
 *
 * Without this, anyone signed in could add anyone else's domain, send
 * webmentions for that domain's URLs to their own endpoint, and have them
 * show up in the public API next to the real ones, where the domain's owner
 * could neither see nor remove them.
 */
final class SiteVerifier
{
    private const TIMEOUT = 5;

    /** Same-host redirects followed per candidate URL (http to https, trailing slash, and the like). */
    private const MAX_HOPS = 5;

    /** The hostname every site's tag points at, whatever this deployment's BASE_URL is. */
    private const PUBLIC_BASE = 'https://webmention.io';

    private ?string $matched = null;

    /** @var list<string> */
    private array $found = [];

    public function __construct(
        private readonly HttpClient $http,
        private readonly Config $config,
    ) {
    }

    /** The endpoint tag a site must carry, for showing to the user. */
    public function endpointFor(Account $account): string
    {
        return $this->config->baseUrl() . '/' . rawurlencode((string) ($account->domain ?? $account->username)) . '/webmention';
    }

    /**
     * Null when one of $domain's pages names one of $account's endpoints;
     * otherwise a sentence saying what was found instead.
     *
     * The home page is tried first (https, then http), then $alsoTry, which
     * callers fill with the site's recent target pages: many sites only
     * advertise the endpoint on posts.
     *
     * The proof has to be served by the domain itself. Redirects are followed
     * only while they stay on that host (http to https, a trailing slash) or
     * move between it and its "www." form, which the same person controls; a
     * redirect elsewhere proves nothing, or a link shortener could be claimed
     * by anyone with a short link to their own site.
     *
     * @param list<string> $alsoTry
     */
    public function verify(Account $account, string $domain, array $alsoTry = []): ?string
    {
        $domain   = strtolower($domain);
        $accepted = $this->acceptedEndpoints($account, $domain);
        $http     = $this->http->http(self::TIMEOUT);
        $http->set_max_redirects(0);
        $problem  = null;

        $this->matched = null;
        $this->found   = [];

        $urls = ["https://$domain/", "http://$domain/"];
        foreach ($alsoTry as $url) {
            if (Url::isHttp($url) && Url::host($url) === $domain && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        foreach ($urls as $start) {
            $url = $start;

            for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
                $response = $http->get($url, ['Accept: text/html']);
                $code     = (int) ($response['code'] ?? 0);

                if (!empty($response['error']) || $code >= 400 || $code === 0) {
                    $problem ??= "Could not fetch $url: " . (!empty($response['error'])
                        ? (string) ($response['error_description'] ?: $response['error'])
                        : "HTTP $code");
                    break;
                }

                // A Link header counts on every hop, including a redirect's.
                $found = self::endpoints($response, $url, includeBody: $code < 300);
                foreach ($found as $endpoint) {
                    if (!in_array($endpoint, $this->found, true)) {
                        $this->found[] = $endpoint;
                    }
                }
                foreach ($found as $endpoint) {
                    if (in_array(self::normalize($endpoint), $accepted, true)) {
                        $this->matched = $endpoint;

                        return null;
                    }
                }

                if ($code >= 300) {
                    $next = self::location($response, $url);
                    if ($next === null) {
                        $problem ??= "Could not fetch $url: HTTP $code without a Location header";
                        break;
                    }
                    if (!Url::sameOwner(Url::host($next), $domain)) {
                        $problem ??= "$url redirects to " . Url::host($next) . ", which does not prove $domain is yours.";
                        break;
                    }
                    $url = $next;
                    continue;
                }

                // A page that answered but names no endpoint of ours is the most useful thing to report.
                $problem = $found === []
                    ? "$url does not have a webmention endpoint."
                    : "$url points to a different webmention endpoint (" . $found[0] . ').';
                break;
            }
        }

        return $problem ?? "Could not fetch https://$domain/.";
    }

    /** The endpoint that satisfied the last verify(), or null if none did. */
    public function matchedEndpoint(): ?string
    {
        return $this->matched;
    }

    /** @return list<string> Every endpoint the last verify() saw the domain advertise, in order. */
    public function foundEndpoints(): array
    {
        return $this->found;
    }

    /**
     * The account an endpoint of this service names: "alice.example" for
     * https://webmention.io/alice.example/webmention. Null for any other URL,
     * and for the /d/{domain}/webmention form, which any account may claim
     * and so names nobody.
     */
    public function accountNamedBy(string $endpoint): ?string
    {
        $endpoint = self::normalize($endpoint);
        foreach (array_unique([$this->config->baseUrl(), self::PUBLIC_BASE]) as $base) {
            $base = self::normalize($base) . '/';
            if (!str_starts_with($endpoint, $base)) {
                continue;
            }
            $parts = explode('/', substr($endpoint, strlen($base)));
            if (count($parts) === 2 && $parts[1] === 'webmention' && $parts[0] !== 'd' && $parts[0] !== '') {
                return rawurldecode($parts[0]);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $response */
    private static function location(array $response, string $url): ?string
    {
        if (preg_match('/^location:\s*(\S.*)$/im', (string) ($response['header'] ?? ''), $m) !== 1) {
            return null;
        }

        $next = \Mf2\resolveUrl($url, trim($m[1]));

        return Url::isHttp($next) ? $next : null;
    }

    /**
     * @return list<string>
     */
    private function acceptedEndpoints(Account $account, string $domain): array
    {
        $names = array_unique(array_filter([(string) $account->username, (string) $account->domain]));

        // Sites advertise the public hostname, which a test or staging
        // deployment with another BASE_URL still has to recognise.
        $accepted = [];
        foreach (array_unique([$this->config->baseUrl(), self::PUBLIC_BASE]) as $base) {
            $accepted[] = self::normalize("$base/d/$domain/webmention");
            foreach ($names as $name) {
                $accepted[] = self::normalize($base . '/' . rawurlencode($name) . '/webmention');
                $accepted[] = self::normalize("$base/$name/webmention");
            }
        }

        return array_values(array_unique($accepted));
    }

    /**
     * Webmention endpoints advertised by a response: the Link header first,
     * then the document, both resolved against the page.
     *
     * @param  array<string, mixed> $response
     * @return list<string>
     */
    private static function endpoints(array $response, string $url, bool $includeBody = true): array
    {
        $found = [];

        foreach ((array) ($response['rels']['webmention'] ?? []) as $endpoint) {
            if (is_string($endpoint) && $endpoint !== '') {
                $found[] = \Mf2\resolveUrl($url, $endpoint);
            }
        }

        $body = $includeBody ? (string) ($response['body'] ?? '') : '';
        if ($body !== '') {
            try {
                $parsed = \Mf2\parse($body, $url);
                foreach ($parsed['rels']['webmention'] ?? [] as $endpoint) {
                    if (is_string($endpoint) && $endpoint !== '') {
                        $found[] = $endpoint;
                    }
                }
            } catch (\Throwable) {
                // An unparseable page simply has no endpoint.
            }
        }

        return array_values(array_unique($found));
    }

    private static function normalize(string $url): string
    {
        return rtrim(Url::normalize(trim($url)), '/');
    }
}
