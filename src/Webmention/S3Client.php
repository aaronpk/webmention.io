<?php

declare(strict_types=1);

namespace Webmention\Webmention;

/**
 * Just enough of S3 for the avatar archive: signed GET and PUT of one object,
 * with AWS Signature Version 4. Requests go through HttpClient, so they get
 * the same transport as everything else.
 */
final class S3Client
{
    private const SERVICE = 's3';

    /** @var callable(): int */
    private $clock;

    /** @param (callable(): int)|null $clock Seconds since the epoch; fixed in tests. */
    public function __construct(
        private readonly string $keyId,
        private readonly string $secretKey,
        private readonly string $region,
        private readonly string $bucket,
        private readonly HttpClient $http,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** Virtual-hosted style; us-east-1 is the one region without its name in the host. */
    public function host(): string
    {
        return $this->region === 'us-east-1'
            ? "{$this->bucket}.s3.amazonaws.com"
            : "{$this->bucket}.s3.{$this->region}.amazonaws.com";
    }

    /** The object's body, or null if it does not exist or can't be read. */
    public function get(string $key): ?string
    {
        $headers  = $this->signedHeaders('GET', $key, [], hash('sha256', ''));
        $response = $this->http->http(10)->get($this->url($key), $headers);

        return (int) ($response['code'] ?? 0) === 200 && empty($response['error']) ? (string) $response['body'] : null;
    }

    /** Store a publicly readable object. */
    public function put(string $key, string $body, string $contentType): bool
    {
        $headers  = $this->signedHeaders('PUT', $key, ['content-type' => $contentType, 'x-amz-acl' => 'public-read'], hash('sha256', $body));
        $response = $this->http->http(20)->put($this->url($key), $body, $headers);

        return HttpClient::succeeded($response);
    }

    /**
     * The request headers, Authorization included, as "Name: value" lines.
     *
     * @param array<string, string> $headers Lowercase names; all are signed.
     * @return list<string>
     */
    public function signedHeaders(string $method, string $key, array $headers, string $payloadHash): array
    {
        $now  = ($this->clock)();
        $date = gmdate('Ymd', $now);

        $headers += [
            'host'                 => $this->host(),
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'           => gmdate('Ymd\THis\Z', $now),
        ];
        ksort($headers);

        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim($value) . "\n";
        }
        $signedNames = implode(';', array_keys($headers));

        $canonicalRequest = implode("\n", [$method, self::path($key), '', $canonicalHeaders, $signedNames, $payloadHash]);

        $scope        = "$date/{$this->region}/" . self::SERVICE . '/aws4_request';
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $headers['x-amz-date'], $scope, hash('sha256', $canonicalRequest)]);

        $signingKey = 'AWS4' . $this->secretKey;
        foreach ([$date, $this->region, self::SERVICE, 'aws4_request'] as $part) {
            $signingKey = hash_hmac('sha256', $part, $signingKey, true);
        }
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $lines = [];
        foreach ($headers as $name => $value) {
            if ($name !== 'host') {
                $lines[] = "$name: $value";
            }
        }
        $lines[] = "Authorization: AWS4-HMAC-SHA256 Credential={$this->keyId}/$scope, SignedHeaders=$signedNames, Signature=$signature";

        return $lines;
    }

    private function url(string $key): string
    {
        return 'https://' . $this->host() . self::path($key);
    }

    /** Each segment encoded once, slashes kept, as S3 expects. */
    private static function path(string $key): string
    {
        return '/' . implode('/', array_map(rawurlencode(...), explode('/', $key)));
    }
}
