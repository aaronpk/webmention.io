<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use Webmention\Config;
use Webmention\Logging\Log;

/**
 * Copies author photos to our own storage (via the CA3DB service), so
 * mentions keep their avatar after the author changes or deletes it.
 *
 * The stored URL is the archive's public address with CA3DB_S3_URL replaced
 * by CA3DB_AVATAR_URL, which defaults to BASE_URL/avatar. A deployment on
 * another hostname that shares the production database sets it to the
 * production value, so the rows it writes look the same as everyone else's.
 */
final class AvatarArchiver
{
    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $http,
        private readonly Log $log,
    ) {
    }

    /** The archived URL, or the original URL if archiving is off or fails. */
    public function archive(string $originalUrl): string
    {
        $endpoint = $this->config->get('CA3DB_API_ENDPOINT');

        if ($endpoint === null || $originalUrl === '') {
            return $originalUrl;
        }

        // CA3DB hands SVGs back with a download disposition, so a browser
        // won't show them (issue 204). Until it rasterises them or serves
        // image/svg+xml safely, an SVG avatar keeps its original URL.
        if (preg_match('/\.svg$/i', (string) parse_url($originalUrl, PHP_URL_PATH)) === 1) {
            return $originalUrl;
        }

        // CA3DB fetches whatever URL it is given, so it only gets ones this app
        // would fetch itself.
        if ($this->http->blockedReason($originalUrl) !== null) {
            return $originalUrl;
        }

        $response = $this->http->postJson($endpoint, [
            'key_id'     => $this->config->get('CA3DB_KEY_ID'),
            'secret_key' => $this->config->get('CA3DB_SECRET_KEY'),
            'region'     => $this->config->get('CA3DB_REGION'),
            'bucket'     => $this->config->get('CA3DB_BUCKET'),
            'url'        => $originalUrl,
            'max_height' => 96,
        ], ['x-api-key: ' . $this->config->get('CA3DB_API_KEY', '')]);

        $data = HttpClient::succeeded($response) ? json_decode((string) $response['body'], true) : null;

        if (!is_array($data) || !isset($data['url']) || !is_string($data['url'])) {
            $this->log->warning("Avatar archive failed for $originalUrl: " . ($response['error'] ?: 'HTTP ' . ($response['code'] ?? '?')));

            return $originalUrl;
        }

        $s3Url = $this->config->get('CA3DB_S3_URL', '') ?? '';

        return $s3Url !== '' && str_starts_with($data['url'], $s3Url)
            ? $this->avatarUrlPrefix() . '/' . ltrim(substr($data['url'], strlen($s3Url)), '/')
            : $data['url'];
    }

    /** What replaces CA3DB_S3_URL in stored avatar URLs, without a trailing slash. */
    public function avatarUrlPrefix(): string
    {
        return rtrim($this->config->get('CA3DB_AVATAR_URL') ?? $this->config->baseUrl() . '/avatar', '/');
    }
}
