<?php

declare(strict_types=1);

namespace Webmention\Webmention;

use GdImage;
use Webmention\Config;
use Webmention\Logging\Log;

/**
 * Copies author photos to our own S3 bucket, so mentions keep their avatar
 * after the author changes or deletes it. A port of the ca3db service it
 * replaces, storing objects the same way so existing ones are reused.
 *
 * Content-addressed: each image is stored as {host}/{sha256 of the original
 * bytes}.{ext} next to a {key}.meta text file recording its type, the date and
 * the URL it came from. The same image is stored once; a changed image at the
 * same URL gets a new key, and the old one stays.
 *
 * The stored URL is AVATAR_URL/{key}.{ext}, defaulting to BASE_URL/avatar,
 * which the web server maps onto the bucket. A deployment on another hostname
 * that shares the production database sets it to the production value, so
 * the rows it writes look the same as everyone else's.
 */
final class AvatarArchiver
{
    public const MAX_HEIGHT = 96;

    /** Content types an avatar may be stored as, by the extension ca3db used. */
    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'ico' => 'image/x-icon'];

    /** What getimagesizefromstring() must find for the types it can check. */
    private const IMAGETYPES = ['png' => IMAGETYPE_PNG, 'jpg' => IMAGETYPE_JPEG, 'gif' => IMAGETYPE_GIF];

    public function __construct(
        private readonly Config $config,
        private readonly ?S3Client $s3,
        private readonly HttpClient $http,
        private readonly Log $log,
    ) {
    }

    /** Archiving needs a bucket and credentials; without them photos keep their URLs. */
    public static function s3FromConfig(Config $config, HttpClient $http): ?S3Client
    {
        $bucket = $config->get('AVATAR_S3_BUCKET');
        $keyId  = $config->get('AVATAR_S3_KEY_ID');
        $secret = $config->get('AVATAR_S3_SECRET_KEY');

        if ($bucket === null || $keyId === null || $secret === null) {
            return null;
        }

        return new S3Client($keyId, $secret, $config->get('AVATAR_S3_REGION', 'us-east-1') ?? 'us-east-1', $bucket, $http);
    }

    /** The archived URL, or the original URL if archiving is off or fails. */
    public function archive(string $originalUrl): string
    {
        if ($this->s3 === null || $originalUrl === '') {
            return $originalUrl;
        }

        // Served from our own /avatar path, an SVG could run script on this
        // origin, so an SVG avatar keeps its original URL (issue 204).
        if (preg_match('/\.svg$/i', (string) parse_url($originalUrl, PHP_URL_PATH)) === 1) {
            return $originalUrl;
        }

        $host = strtolower((string) parse_url($originalUrl, PHP_URL_HOST));
        if ($host === '') {
            return $originalUrl;
        }

        $response = $this->http->http(10)->get($originalUrl);
        if ((int) ($response['code'] ?? 0) !== 200 || !empty($response['error'])) {
            return $this->failed($originalUrl, $response['error'] ?: 'HTTP ' . ($response['code'] ?? '?'));
        }

        $body = (string) $response['body'];
        $ext  = self::extension($response['headers'] ?? []);
        if ($ext === null || $body === '') {
            return $this->failed($originalUrl, 'not a recognised image type');
        }

        if (isset(self::IMAGETYPES[$ext]) && (@getimagesizefromstring($body)[2] ?? null) !== self::IMAGETYPES[$ext]) {
            return $this->failed($originalUrl, "body is not a $ext image");
        }

        $key = $host . '/' . hash('sha256', $body);

        // Already archived: keep the type it was first stored as.
        $meta = $this->s3->get("$key.meta");
        if ($meta !== null) {
            if (preg_match('/^type: ([a-z]+)$/m', $meta, $m) === 1) {
                $ext = $m[1];
            }

            return $this->avatarUrlPrefix() . "/$key.$ext";
        }

        $image = isset(self::IMAGETYPES[$ext]) ? self::resize($body, $ext) ?? $body : $body;

        // The image first, so a .meta file never names an object that isn't there.
        $metadata = 'date: ' . gmdate('Y-m-d\TH:i:s') . "\ntype: $ext\nurl: $originalUrl\n";
        if (!$this->s3->put("$key.$ext", $image, self::TYPES[$ext]) || !$this->s3->put("$key.meta", $metadata, 'text/plain')) {
            return $this->failed($originalUrl, 'upload to S3 failed');
        }

        return $this->avatarUrlPrefix() . "/$key.$ext";
    }

    /** What precedes the key in stored avatar URLs, without a trailing slash. */
    public function avatarUrlPrefix(): string
    {
        return rtrim($this->config->get('AVATAR_URL') ?? $this->config->baseUrl() . '/avatar', '/');
    }

    /**
     * The type named by Content-Type, or failing that by a Content-Disposition
     * filename, as ca3db read them.
     *
     * @param array<string, string|list<string>> $headers
     */
    private static function extension(array $headers): ?string
    {
        $header = static function (string $name) use ($headers): string {
            $value = $headers[$name] ?? '';

            return is_array($value) ? (string) end($value) : $value;
        };

        $ext = null;
        if (preg_match('#image/(png|jpg|jpeg|gif|ico|svg)#i', $header('Content-Type'), $m) === 1) {
            $ext = strtolower($m[1]);
        } elseif (preg_match('/filename=.+\.(png|jpg|jpeg|gif|ico|svg)/i', $header('Content-Disposition'), $m) === 1) {
            $ext = strtolower($m[1]);
        }

        $ext = $ext === 'jpeg' ? 'jpg' : $ext;

        return $ext !== null && isset(self::TYPES[$ext]) ? $ext : null;
    }

    /** Scaled down to MAX_HEIGHT if taller, in the same format; null to store it as it is. */
    private static function resize(string $body, string $ext): ?string
    {
        $size = @getimagesizefromstring($body);
        if ($size === false || $size[1] <= self::MAX_HEIGHT || $size[0] < 1) {
            return null;
        }

        $source = @imagecreatefromstring($body);
        if (!$source instanceof GdImage) {
            return null;
        }

        $width = max(1, (int) round($size[0] * self::MAX_HEIGHT / $size[1]));
        $image = imagecreatetruecolor($width, self::MAX_HEIGHT);
        if (!$image instanceof GdImage) {
            return null;
        }

        if ($ext !== 'jpg') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        }
        imagecopyresampled($image, $source, 0, 0, 0, 0, $width, self::MAX_HEIGHT, $size[0], $size[1]);

        ob_start();
        $ok = match ($ext) {
            'png' => imagepng($image),
            'gif' => imagegif($image),
            default => imagejpeg($image, null, 90),
        };
        $out = (string) ob_get_clean();

        return $ok && $out !== '' ? $out : null;
    }

    private function failed(string $originalUrl, string $reason): string
    {
        $this->log->warning("Avatar archive failed for $originalUrl: $reason");

        return $originalUrl;
    }
}
