<?php

declare(strict_types=1);

namespace Webmention\Auth;

use IndieAuth\Client;
use Webmention\Format\Url;
use Webmention\Webmention\PinnedHttp;

/**
 * What happened at each stage of a sign-in, for the page shown when one
 * fails and for the log. Built from what is already at hand when a stage
 * fails: the transport's trace of requests, the client library's cached
 * discovery results (read back without new fetches), the session keys saved
 * at the start, and the library's exchange details.
 *
 * Stages: site (the website was fetched), discovery (an endpoint was found
 * on it), metadata (the server's metadata document was read), authorize
 * (sent to the authorization server), return (came back with a code),
 * exchange (the code was redeemed), profile (the profile URL was accepted).
 *
 * A stage is an array of stage, label, state (ok, failed, skipped), summary,
 * details (label/value pairs) and hint. Nothing secret goes in: URLs are
 * masked by the transport, exchange bodies are scrubbed of tokens, and
 * state values are never shown.
 *
 * @phpstan-type Stage array{stage: string, label: string, state: 'ok'|'failed'|'skipped', summary: string, details: list<array{label: string, value: string}>, hint: ?string}
 */
final class SignInReport
{
    public const LABELS = [
        'site'      => 'Your website',
        'discovery' => 'IndieAuth endpoint discovery',
        'metadata'  => 'Authorization server metadata',
        'authorize' => 'Sent to your authorization server',
        'return'    => 'Returned from your authorization server',
        'exchange'  => 'Authorization code redeemed',
        'profile'   => 'Profile URL accepted',
    ];

    private const HINTS = [
        'site'      => 'Check the address, and that the site answers over https from the public internet.',
        'discovery' => 'Add <link rel="indieauth-metadata" href="…"> to the <head> of your home page, pointing at your IndieAuth server\'s metadata document. Older servers use rel="authorization_endpoint" instead. A site with neither can sign in through indielogin.com with rel="me" links.',
        'metadata'  => 'The metadata document must be JSON with an issuer that is an https URL and a prefix of the document\'s own URL, plus authorization_endpoint and token_endpoint. It is served by your IndieAuth server, so the fix is usually there.',
        'authorize' => 'Start again from the sign-in form.',
        'return'    => 'Start again from the sign-in form. If it keeps happening, your authorization server is sending back a different state or iss than it was given.',
        'exchange'  => 'Your authorization server has to answer the code exchange with JSON containing your profile URL as "me". What it answered is shown below.',
        'profile'   => 'The profile URL your server returns must be your own site without a query string, and if it differs from the address you entered, it must declare the same authorization endpoint.',
    ];

    /** How many characters of a response body are shown. */
    private const EXCERPT = 300;

    /**
     * Stages 1 to 4, after Client::begin() ran for $me on $http. $error is
     * the library's failure, or the app's (unreachable), or null on success.
     *
     * @param  array<string, mixed>|null $error
     * @return list<Stage>
     */
    public static function discovery(string $me, PinnedHttp $http, ?array $error): array
    {
        $code   = (string) ($error['error'] ?? '');
        $stages = [];

        // 1. The site itself: the first GET to its host (the library HEADs first).
        $host = Url::host($me);
        $site = null;
        foreach ($http->requests() as $request) {
            if (Url::host($request['url']) === $host && ($site === null || ($site['method'] !== 'GET' && $request['method'] === 'GET'))) {
                $site = $request;
            }
        }
        $siteDetails = self::requestDetails($site);
        if ($site === null) {
            $stages[] = self::stage('site', $code === '' ? 'skipped' : 'failed', 'Nothing was fetched.', $siteDetails);
        } elseif ($site['error'] !== null || $site['status'] >= 400) {
            $stages[] = self::stage('site', 'failed', "Could not fetch {$site['url']}: " . ($site['error'] ?? "HTTP {$site['status']}") . '.', $siteDetails);
        } else {
            $moved     = $site['final_url'] !== $site['url'] ? ", ending up at {$site['final_url']}" : '';
            $stages[]  = self::stage('site', 'ok', "Fetched {$site['url']} (HTTP {$site['status']}$moved).", $siteDetails);
        }
        $siteOk = end($stages)['state'] === 'ok';

        // 2. Discovery: what the page advertised. All cached by the library.
        $metadataUrl = $siteOk ? (Client::discoverMetadataEndpoint($me) ?: null) : null;
        $metadata    = $metadataUrl !== null ? Client::getMetadata() : null;
        $legacyAuth  = $siteOk && $metadataUrl === null ? (Client::discoverAuthorizationEndpoint($me) ?: null) : null;

        if (!$siteOk) {
            $stages[] = self::stage('discovery', 'skipped', 'Not reached.', []);
        } elseif ($metadataUrl !== null) {
            $stages[] = self::stage('discovery', 'ok', 'The page links to an IndieAuth metadata document.', [['label' => 'rel="indieauth-metadata"', 'value' => (string) $metadataUrl]]);
        } elseif ($legacyAuth !== null) {
            $stages[] = self::stage('discovery', 'ok', 'The page links to an authorization endpoint the older way, without a metadata document.', [['label' => 'rel="authorization_endpoint"', 'value' => (string) $legacyAuth]]);
        } else {
            $stages[] = self::stage('discovery', 'failed', 'The page has no rel="indieauth-metadata" and no rel="authorization_endpoint" link, in its HTTP headers or its HTML.', []);
        }

        // 3. Metadata, when there is a document to read.
        if ($metadataUrl === null) {
            $stages[] = self::stage('metadata', $legacyAuth !== null ? 'ok' : 'skipped', $legacyAuth !== null ? 'Not needed: the endpoint was given directly.' : 'Not reached.', []);
        } else {
            $stages[] = self::metadataStage((string) $metadataUrl, is_array($metadata) ? $metadata : null, $http->requestFor((string) $metadataUrl), $code);
        }

        // 4. Off to the authorization server.
        $authEndpoint = is_array($metadata) ? ($metadata['authorization_endpoint'] ?? null) : $legacyAuth;
        if ($error === null && is_string($authEndpoint)) {
            $stages[] = self::stage('authorize', 'ok', "You were sent to $authEndpoint.", [['label' => 'authorization_endpoint', 'value' => $authEndpoint]]);
        } elseif ($error !== null && !in_array('failed', array_column($stages, 'state'), true)) {
            // The library refused for a reason the stages above did not catch.
            $stages[] = self::stage('authorize', 'failed', trim((string) ($error['error_description'] ?? $code)) . '.', []);
        } else {
            $stages[] = self::stage('authorize', 'skipped', 'Not reached.', []);
        }

        return $stages;
    }

    /**
     * Stages 5 to 7 added to the stages saved at the start.
     *
     * @param  list<Stage>                                                  $started   From discovery(), kept in the session.
     * @param  array<string, mixed>                                         $query     The callback's query string.
     * @param  list<array{endpoint: string, debug: array<string, mixed>|null}> $attempts Each place the code was redeemed, in order.
     * @param  array<string, mixed>|null                                    $error     The failure being reported, if any.
     * @return list<Stage>
     */
    public static function exchange(array $started, array $query, ?string $expectedIssuer, array $attempts, ?array $error, ?string $returnedMe, ?string $entered): array
    {
        $stages = $started;
        $code   = (string) ($error['error'] ?? '');

        // 5. What came back.
        if (isset($query['error'])) {
            $stages[] = self::stage('return', 'failed', 'Your authorization server sent back an error instead of a code: ' . (string) $query['error'] . (isset($query['error_description']) ? ' (' . (string) $query['error_description'] . ')' : '') . '.', []);
        } elseif (!isset($query['code'])) {
            $stages[] = self::stage('return', 'failed', 'Your authorization server sent you back without an authorization code.', []);
        } elseif (in_array($code, ['missing_state', 'invalid_state', 'invalid_session'], true)) {
            $stages[] = self::stage('return', 'failed', $code === 'invalid_session'
                ? 'This browser has no sign-in in progress; the session may have expired or cookies are blocked.'
                : 'The state value that came back is not the one this sign-in started with.', []);
        } elseif (in_array($code, ['missing_iss', 'invalid_iss'], true)) {
            $stages[] = self::stage('return', 'failed', 'The iss value that came back does not name the server the sign-in started with.', array_values(array_filter([
                ['label' => 'Expected iss', 'value' => (string) $expectedIssuer],
                isset($query['iss']) ? ['label' => 'Received iss', 'value' => (string) $query['iss']] : null,
            ])));
        } else {
            $details = $expectedIssuer !== null ? [['label' => 'iss', 'value' => (string) ($query['iss'] ?? '(none)')]] : [];
            $stages[] = self::stage('return', 'ok', 'An authorization code and the right state came back' . ($expectedIssuer !== null && isset($query['iss']) ? ', with the expected iss' : '') . '.', $details);
        }
        $returned = end($stages)['state'] === 'ok';

        // 6. The exchange, possibly tried at two endpoints.
        if (!$returned || $attempts === []) {
            $stages[] = self::stage('exchange', 'skipped', 'Not reached.', []);
        } else {
            $failedExchange = in_array($code, ['indieauth_error'], true) || (is_array($error['debug'] ?? null) && $code !== 'invalid_authorization_endpoint');
            $details        = [];
            $summary        = [];
            foreach ($attempts as $i => $attempt) {
                $prefix = count($attempts) > 1 ? 'Attempt ' . ($i + 1) . ': ' : '';
                $details[] = ['label' => $prefix . 'endpoint', 'value' => $attempt['endpoint']];
                $debug = $attempt['debug'];
                if (!is_array($debug)) {
                    continue;
                }
                $response = is_array($debug['response_details'] ?? null) ? $debug['response_details'] : [];
                $status   = (int) ($debug['response_code'] ?? $response['code'] ?? 0);
                $type     = preg_match('/^content-type:\s*([^\r\n]+)/im', (string) ($response['header'] ?? ''), $m) === 1 ? trim($m[1]) : null;
                $transport = trim((string) ($response['error_description'] ?? '') ?: (string) ($response['error'] ?? ''));
                $details[] = ['label' => $prefix . 'answer', 'value' => ($status > 0 ? "HTTP $status" : 'no response') . ($type !== null ? ", $type" : '') . ($transport !== '' ? ", $transport" : '')];
                $body = is_array($debug['response'] ?? null) ? $debug['response'] : [];
                if (isset($body['error'])) {
                    $details[] = ['label' => $prefix . 'server error', 'value' => (string) $body['error'] . (isset($body['error_description']) ? ': ' . (string) $body['error_description'] : '')];
                }
                $details[] = ['label' => $prefix . 'profile URL in the answer', 'value' => is_string($body['me'] ?? null) && $body['me'] !== '' ? (string) $body['me'] : 'none'];
                $excerpt = self::excerpt((string) ($debug['raw_response'] ?? ''));
                $details[] = ['label' => $prefix . 'body', 'value' => $excerpt === '' ? '(empty)' : $excerpt];
                $summary[] = ($status > 0 ? "HTTP $status" : 'no response') . ' from ' . $attempt['endpoint'];
            }
            if ($failedExchange) {
                $stages[] = self::stage('exchange', 'failed', 'The code was sent to your authorization server, which did not answer with your profile URL (' . implode('; ', $summary) . ').', $details);
            } else {
                $stages[] = self::stage('exchange', 'ok', 'Your authorization server accepted the code (' . implode('; ', $summary) . ').', $details);
            }
        }
        $exchanged = end($stages)['state'] === 'ok';

        // 7. The profile URL.
        if (!$exchanged) {
            $stages[] = self::stage('profile', 'skipped', 'Not reached.', []);
        } else {
            $details = array_values(array_filter([
                $returnedMe !== null ? ['label' => 'Returned profile URL', 'value' => $returnedMe] : null,
                $entered !== null ? ['label' => 'Address you entered', 'value' => $entered] : null,
            ]));
            if ($error !== null) {
                $stages[] = self::stage('profile', 'failed', trim((string) ($error['error_description'] ?? $code)) . '', $details);
            } else {
                $stages[] = self::stage('profile', 'ok', 'Signed in as ' . ($returnedMe ?? $entered ?? '') . '.', $details);
            }
        }

        return $stages;
    }

    /**
     * The indielogin.com path: the hand-off and its token exchange, after the
     * discovery stages that led there.
     *
     * @param  list<Stage>               $started
     * @param  array<string, mixed>|null $response The token POST's response, if it got that far.
     * @param  array<string, mixed>|null $error
     * @return list<Stage>
     */
    public static function indielogin(array $started, string $indielogin, array $query, ?array $response, ?array $error): array
    {
        $code   = (string) ($error['error'] ?? '');
        $stages = [];
        foreach ($started as $stage) {
            if ($stage['stage'] === 'site') {
                $stages[] = $stage;
            } elseif ($stage['stage'] === 'discovery') {
                // Not a failure on this path: it is why indielogin.com was used.
                $stages[] = self::stage('discovery', 'ok', 'The page has no IndieAuth endpoint (no rel="indieauth-metadata" or rel="authorization_endpoint" link), so indielogin.com signs you in with your rel="me" links.', []);
            }
        }
        $stages[] = self::stage('metadata', 'skipped', 'Not needed.', []);
        $stages[] = self::stage('authorize', 'ok', "You were sent to $indielogin.", [['label' => 'Sign-in service', 'value' => $indielogin]]);

        if (isset($query['error'])) {
            $stages[] = self::stage('return', 'failed', "$indielogin sent back an error instead of a code: " . (string) $query['error'] . '.', []);
        } elseif (in_array($code, ['invalid_state', 'invalid_issuer', 'invalid_response'], true)) {
            $stages[] = self::stage('return', 'failed', trim((string) ($error['error_description'] ?? $code)), []);
        } else {
            $stages[] = self::stage('return', 'ok', 'An authorization code and the right state came back.', []);
        }

        if (end($stages)['state'] !== 'ok') {
            $stages[] = self::stage('exchange', 'skipped', 'Not reached.', []);
            $stages[] = self::stage('profile', 'skipped', 'Not reached.', []);

            return $stages;
        }

        $status  = (int) ($response['code'] ?? 0);
        $details = [
            ['label' => 'endpoint', 'value' => "$indielogin/token"],
            ['label' => 'answer', 'value' => $status > 0 ? "HTTP $status" : 'no response'],
            ['label' => 'body', 'value' => self::excerpt((string) ($response['body'] ?? '')) ?: '(empty)'],
        ];
        if ($error !== null) {
            $stages[] = self::stage('exchange', 'failed', "$indielogin did not confirm the sign-in: " . trim((string) ($error['error_description'] ?? $code)), $details);
            $stages[] = self::stage('profile', 'skipped', 'Not reached.', []);
        } else {
            $stages[] = self::stage('exchange', 'ok', "$indielogin confirmed the sign-in.", $details);
        }

        return $stages;
    }

    /**
     * A profile stage failure appended to whatever came before, for
     * signInAs() refusing the returned URL.
     *
     * @param  list<Stage> $stages
     * @return list<Stage>
     */
    public static function profileRefused(array $stages, string $me, string $why): array
    {
        $stages   = array_values(array_filter($stages, static fn (array $s): bool => $s['stage'] !== 'profile'));
        $stages[] = self::stage('profile', 'failed', $why, [['label' => 'Returned profile URL', 'value' => $me]]);

        return $stages;
    }

    /**
     * One compact line for the log: `stage=state` for each, plus the failed
     * stage's summary.
     *
     * @param list<Stage> $stages
     */
    public static function logLine(array $stages): string
    {
        $parts  = [];
        $failed = null;
        foreach ($stages as $s) {
            $parts[] = $s['stage'] . '=' . ($s['state'] === 'skipped' ? '-' : $s['state']);
            if ($s['state'] === 'failed' && $failed === null) {
                $failed = $s['summary'];
            }
        }

        return ' {' . implode(' ', $parts) . ($failed !== null ? '; ' . $failed : '') . '}';
    }

    /** A body excerpt with any tokens in it hidden. */
    public static function excerpt(string $body): string
    {
        $one = trim((string) preg_replace('/\s+/', ' ', self::scrub($body)));

        return mb_strimwidth($one, 0, self::EXCERPT, '…');
    }

    /** Hide access and refresh token values, in JSON or form encoding. */
    public static function scrub(string $text): string
    {
        $text = (string) preg_replace('/("(?:access|refresh)_token"\s*:\s*")[^"]*(")/i', '$1…$2', $text);

        return (string) preg_replace('/((?:^|[&?])(?:access|refresh)_token=)[^&\s]*/i', '$1…', $text);
    }

    /**
     * @param  array<string, mixed>|null              $metadata
     * @param  array<string, mixed>|null              $request  The trace entry for the metadata fetch.
     * @return Stage
     */
    private static function metadataStage(string $url, ?array $metadata, ?array $request, string $code): array
    {
        $details = self::requestDetails($request);
        if ($request !== null && ($request['error'] !== null || $request['status'] >= 400 || $request['status'] === 0)) {
            return self::stage('metadata', 'failed', "Could not fetch $url: " . ($request['error'] ?? "HTTP {$request['status']}") . '.', $details);
        }
        if ($metadata === null) {
            return self::stage('metadata', 'failed', "$url is not a JSON document.", $details);
        }

        foreach (['issuer', 'authorization_endpoint', 'token_endpoint'] as $key) {
            $details[] = ['label' => $key, 'value' => is_string($metadata[$key] ?? null) ? (string) $metadata[$key] : '(missing)'];
        }

        $issuer = $metadata['issuer'] ?? null;
        if (!is_string($issuer) || $issuer === '') {
            return self::stage('metadata', 'failed', 'The metadata document has no issuer.', $details);
        }
        if (($why = self::issuerProblem($issuer, $url)) !== null) {
            return self::stage('metadata', 'failed', "The issuer is not valid: $why.", $details);
        }
        if (!is_string($metadata['authorization_endpoint'] ?? null)) {
            return self::stage('metadata', 'failed', 'The metadata document has no authorization_endpoint.', $details);
        }
        if ($code === 'invalid_issuer') {
            return self::stage('metadata', 'failed', 'The issuer was refused.', $details);
        }

        return self::stage('metadata', 'ok', 'The metadata document was read and its issuer matches its URL.', $details);
    }

    /**
     * Why an issuer fails the rule for the metadata document it appears in,
     * or null. The issuer must be a prefix of the document's URL (IndieAuth),
     * or the document must be at the issuer's RFC 8414 well-known location
     * (the next IndieAuth draft, and IndieKey.id today). Mirrors
     * IndieAuth\Client::_isIssuerValid() so the page can say which rule failed.
     */
    public static function issuerProblem(string $issuer, string $metadataUrl): ?string
    {
        $parts = parse_url($issuer);
        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return 'it must be an https URL';
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return 'it must not have a query string or fragment';
        }
        $document = rtrim(strtolower($metadataUrl), '/');
        if (str_starts_with($document, rtrim(strtolower($issuer), '/')) || $document === self::wellKnownUrl($issuer)) {
            return null;
        }

        return "it must be a prefix of the metadata document's URL, $metadataUrl, or the document must be at the issuer's RFC 8414 location, " . self::wellKnownUrl($issuer);
    }

    /**
     * The RFC 8414 metadata location for an issuer: the well-known path is
     * inserted between the host and the issuer's path. Lowercased, without a
     * trailing slash, for comparison. (OpenID Connect discovery documents
     * follow their own rules and are not IndieAuth metadata.)
     */
    public static function wellKnownUrl(string $issuer): ?string
    {
        $parts = parse_url($issuer);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $base = strtolower($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        $path = rtrim(strtolower($parts['path'] ?? ''), '/');

        return $base . '/.well-known/oauth-authorization-server' . $path;
    }

    /**
     * @param  array<string, mixed>|null $request A trace entry.
     * @return list<array{label: string, value: string}>
     */
    private static function requestDetails(?array $request): array
    {
        if ($request === null) {
            return [];
        }
        $answer = $request['error'] ?? ($request['status'] > 0 ? "HTTP {$request['status']}" : 'no response');
        if ($request['type'] !== null) {
            $answer .= ", {$request['type']}";
        }
        $details = [
            ['label' => 'Request', 'value' => $request['method'] . ' ' . $request['url']],
            ['label' => 'Answer', 'value' => $answer . ($request['bytes'] > 0 ? ", {$request['bytes']} bytes" : '') . " in {$request['ms']} ms"],
        ];
        if ($request['final_url'] !== $request['url']) {
            $details[] = ['label' => 'Redirected to', 'value' => $request['final_url']];
        }

        return $details;
    }

    /**
     * @param  list<array{label: string, value: string}> $details
     * @return Stage
     */
    private static function stage(string $key, string $state, string $summary, array $details): array
    {
        return [
            'stage'   => $key,
            'label'   => self::LABELS[$key],
            'state'   => $state,
            'summary' => $summary,
            'details' => $details,
            'hint'    => $state === 'failed' ? (self::HINTS[$key] ?? null) : null,
        ];
    }
}
