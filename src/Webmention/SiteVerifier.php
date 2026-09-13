<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use Webmention\Config;
use Webmention\Format\Url;
use Webmention\Model\Account;

/**
 * Proof that an account may receive webmentions for a domain: the domain's
 * home page advertises one of the account's endpoints as its webmention
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
     * Null when $domain's home page names one of $account's endpoints;
     * otherwise a sentence saying what was found instead.
     */
    public function verify(Account $account, string $domain): ?string
    {
        $accepted = $this->acceptedEndpoints($account, $domain);
        $http     = $this->http->http(self::TIMEOUT);
        $failure  = null;

        foreach (["https://$domain/", "http://$domain/"] as $url) {
            $response = $http->get($url, ['Accept: text/html']);

            if (!empty($response['error']) || (int) ($response['code'] ?? 0) >= 400) {
                $failure ??= !empty($response['error'])
                    ? (string) ($response['error_description'] ?: $response['error'])
                    : 'HTTP ' . $response['code'];
                continue;
            }

            $found = self::endpoints($response, (string) ($response['url'] ?? $url));

            foreach ($found as $endpoint) {
                if (in_array(self::normalize($endpoint), $accepted, true)) {
                    return null;
                }
            }

            return $found === []
                ? "$url does not have a webmention endpoint yet."
                : "$url points to a different webmention endpoint (" . $found[0] . ').';
        }

        return "Could not fetch https://$domain/: $failure";
    }

    /**
     * @return list<string>
     */
    private function acceptedEndpoints(Account $account, string $domain): array
    {
        $base  = $this->config->baseUrl();
        $names = array_unique(array_filter([(string) $account->username, (string) $account->domain]));

        $accepted = [self::normalize("$base/d/$domain/webmention")];
        foreach ($names as $name) {
            $accepted[] = self::normalize($base . '/' . rawurlencode($name) . '/webmention');
            $accepted[] = self::normalize("$base/$name/webmention");
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
    private static function endpoints(array $response, string $url): array
    {
        $found = [];

        foreach ((array) ($response['rels']['webmention'] ?? []) as $endpoint) {
            if (is_string($endpoint) && $endpoint !== '') {
                $found[] = \Mf2\resolveUrl($url, $endpoint);
            }
        }

        $body = (string) ($response['body'] ?? '');
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
