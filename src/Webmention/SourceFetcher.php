<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use p3k\XRay;
use Throwable;
use Webmention\Format\Url;

/**
 * Fetches and parses pages with XRay, which the Ruby app called as a hosted
 * service. Results use the same shape the service returned: either `data`, or
 * `error` with an optional `error_description`.
 */
final class SourceFetcher
{
    /** The hosted service was called with 12s and gave each of its two fetches half. */
    public const TIMEOUT = 6;

    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * @return array{data?: array<string, mixed>, error?: string, error_description?: string|null}
     */
    public function parse(string $url, ?string $target = null, ?string $accessToken = null): array
    {
        $opts = ['timeout' => self::TIMEOUT, 'accept' => 'html'];
        if ($target !== null) {
            $opts['target'] = $target;
        }
        if ($accessToken !== null) {
            $opts['token'] = $accessToken;
        }

        $http       = $this->http->http(self::TIMEOUT);
        $xray       = new XRay(['timeout' => self::TIMEOUT]);
        $xray->http = $http;

        try {
            $result = $xray->parse($url, $opts);
        } catch (Throwable) {
            $result = ['error' => 'parse_error', 'error_description' => 'There was an error parsing the source URL'];
        }

        // A deleted post answers 410 Gone. XRay doesn't treat that as an error,
        // and throws when the response body is empty.
        if ($http->firstStatus() === 410) {
            return ['error' => 'gone', 'error_description' => 'The URL returned HTTP 410 Gone'];
        }

        if (!empty($result['error'])) {
            return [
                'error'             => $result['error'] === 'unknown' ? 'error' : (string) $result['error'],
                'error_description' => isset($result['error_description']) ? (string) $result['error_description'] : null,
            ];
        }

        if (isset($result['data']) && is_array($result['data'])) {
            return ['data' => $result['data']];
        }

        return ['error' => 'invalid_source', 'error_description' => 'Error retrieving source. No result returned from XRay.'];
    }

    /**
     * Private Webmention: exchange the code sent with the webmention for an
     * access token at the source's token endpoint. Port of XRay's hosted
     * /token route.
     *
     * @return array{access_token?: string, error?: string, error_description?: string|null}|null
     *         Null when the endpoint returned nothing usable.
     */
    public function accessToken(string $source, string $code): ?array
    {
        $http = $this->http->http(self::TIMEOUT * 2);

        try {
            $head = $http->head($source);
        } catch (Throwable) {
            return null;
        }

        if (!empty($head['error'])) {
            return ['error' => (string) $head['error'], 'error_description' => $head['error_description'] ?? null];
        }

        $rels     = is_array($head['rels'] ?? null) ? $head['rels'] : [];
        $endpoint = $rels['token_endpoint'][0] ?? $rels['oauth2-token'][0] ?? null;

        if (!is_string($endpoint) || $endpoint === '') {
            return ['error' => 'no_token_endpoint', 'error_description' => 'No token endpoint was found in the headers'];
        }

        $endpoint = \Mf2\resolveUrl($source, $endpoint);

        // The endpoint comes from a stranger's headers.
        if (!Url::isHttp($endpoint)) {
            return ['error' => 'invalid_token_endpoint', 'error_description' => 'The token endpoint must be an http or https URL'];
        }

        try {
            $response = $http->post($endpoint, http_build_query([
                'grant_type' => 'authorization_code',
                'code'       => $code,
            ]), ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json']);
        } catch (Throwable) {
            return null;
        }

        if (!empty($response['error'])) {
            return [
                'error'             => (string) $response['error'],
                'error_description' => $response['error_description'] ?: 'An unknown error occurred trying to fetch the token',
            ];
        }

        $body = json_decode((string) ($response['body'] ?? ''), true);

        if (is_array($body) && isset($body['access_token']) && is_string($body['access_token'])) {
            return ['access_token' => $body['access_token']];
        }

        if (is_array($body) && !empty($body['error'])) {
            return [
                'error'             => $body['error'] === 'unknown' ? 'error' : (string) $body['error'],
                'error_description' => isset($body['error_description']) ? (string) $body['error_description'] : null,
            ];
        }

        return null;
    }
}
