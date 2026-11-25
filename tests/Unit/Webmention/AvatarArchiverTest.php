<?php

declare(strict_types=1);

namespace Webmention\Tests\Unit\Webmention;

use PHPUnit\Framework\TestCase;
use Webmention\Config;
use Webmention\Logging\Log;
use Webmention\Tests\Support\FakeTransport;
use Webmention\Webmention\AvatarArchiver;
use Webmention\Webmention\HttpClient;

final class AvatarArchiverTest extends TestCase
{
    private const PHOTO  = 'https://alice.example/photo.png';
    private const BUCKET = 'https://bucket.s3.us-west-2.amazonaws.com/';

    private FakeTransport $http;

    protected function setUp(): void
    {
        $this->http = new FakeTransport(dirname(__DIR__, 2) . '/fixtures');
    }

    public function testStoresAResizedCopyAndItsMetadata(): void
    {
        $png = self::png(200, 150);
        $key = 'alice.example/' . hash('sha256', $png);
        $this->http->respond('GET', self::PHOTO, 200, $png, ['Content-Type' => 'image/png']);
        $this->http->respond('PUT', self::BUCKET . "$key.png", 200);
        $this->http->respond('PUT', self::BUCKET . "$key.meta", 200);

        self::assertSame("https://webmention.io/avatar/$key.png", $this->archiver()->archive(self::PHOTO));

        $image = $this->request('PUT', "$key.png");
        $size  = getimagesizefromstring((string) $image['body']);
        self::assertSame([128, 96, IMAGETYPE_PNG], [$size[0], $size[1], $size[2]]);
        self::assertContains('content-type: image/png', $image['headers']);
        self::assertContains('x-amz-acl: public-read', $image['headers']);
        self::assertContains('x-amz-content-sha256: ' . hash('sha256', (string) $image['body']), $image['headers']);
        self::assertMatchesRegularExpression('#^Authorization: AWS4-HMAC-SHA256 Credential=kid/\d{8}/us-west-2/s3/aws4_request, SignedHeaders=content-type;host;x-amz-acl;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}$#', self::authorization($image['headers']));

        $meta = (string) $this->request('PUT', "$key.meta")['body'];
        self::assertMatchesRegularExpression("#^date: \\d{4}-\\d\\d-\\d\\dT\\d\\d:\\d\\d:\\d\\d\ntype: png\nurl: https://alice.example/photo.png\n$#", $meta);

        // The image goes up before the metadata that names it.
        $puts = array_values(array_filter($this->http->requests, static fn (array $r): bool => $r['method'] === 'PUT'));
        self::assertStringEndsWith('.png', $puts[0]['url']);
    }

    public function testSmallImagesAreStoredAsTheyAre(): void
    {
        $gif = self::gif(48, 48);
        $key = 'alice.example/' . hash('sha256', $gif);
        $this->http->respond('GET', self::PHOTO, 200, $gif, ['Content-Type' => 'image/gif']);
        $this->http->respond('PUT', self::BUCKET . "$key.gif", 200);
        $this->http->respond('PUT', self::BUCKET . "$key.meta", 200);

        self::assertSame("https://webmention.io/avatar/$key.gif", $this->archiver()->archive(self::PHOTO));
        self::assertSame($gif, $this->request('PUT', "$key.gif")['body']);
    }

    public function testAnImageAlreadyArchivedIsNotStoredAgain(): void
    {
        $png = self::png(10, 10);
        $key = 'alice.example/' . hash('sha256', $png);
        $this->http->respond('GET', self::PHOTO, 200, $png, ['Content-Type' => 'image/png']);
        $this->http->respond('GET', self::BUCKET . "$key.meta", 200, "date: 2016-01-01T00:00:00\ntype: jpg\nurl: https://alice.example/old\n");

        self::assertSame("https://webmention.io/avatar/$key.jpg", $this->archiver()->archive(self::PHOTO));
        self::assertSame([], array_filter($this->http->requests, static fn (array $r): bool => $r['method'] === 'PUT'));
        self::assertStringStartsWith('Authorization: AWS4-HMAC-SHA256 ', self::authorization($this->request('GET', "$key.meta")['headers']));
    }

    public function testTheTypeCanComeFromTheFilename(): void
    {
        $jpeg = self::jpeg(20, 20);
        $key  = 'alice.example/' . hash('sha256', $jpeg);
        $this->http->respond('GET', self::PHOTO, 200, $jpeg, ['Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment; filename=me.jpeg']);
        $this->http->respond('PUT', self::BUCKET . "$key.jpg", 200);
        $this->http->respond('PUT', self::BUCKET . "$key.meta", 200);

        self::assertSame("https://webmention.io/avatar/$key.jpg", $this->archiver()->archive(self::PHOTO));
        self::assertContains('content-type: image/jpeg', $this->request('PUT', "$key.jpg")['headers']);
    }

    public function testStoredUrlPrefixCanBeSetForADeploymentSharingTheDatabase(): void
    {
        $png = self::png(10, 10);
        $key = 'alice.example/' . hash('sha256', $png);
        $this->http->respond('GET', self::PHOTO, 200, $png, ['Content-Type' => 'image/png']);
        $this->http->respond('PUT', self::BUCKET . "$key.png", 200);
        $this->http->respond('PUT', self::BUCKET . "$key.meta", 200);

        $archiver = $this->archiver(['BASE_URL' => 'https://v2.webmention.io', 'AVATAR_URL' => 'https://webmention.io/avatar/']);

        self::assertSame("https://webmention.io/avatar/$key.png", $archiver->archive(self::PHOTO));
        self::assertSame('https://webmention.io/avatar', $archiver->avatarUrlPrefix());
        self::assertSame('https://v2.webmention.io/avatar', $this->archiver(['BASE_URL' => 'https://v2.webmention.io'])->avatarUrlPrefix());
    }

    public function testOriginalUrlIsKeptWhenThePhotoIsNotAnImage(): void
    {
        $this->http->respond('GET', 'https://alice.example/page', 200, '<html></html>', ['Content-Type' => 'text/html']);
        $this->http->respond('GET', 'https://alice.example/fake.png', 200, '<script>alert(1)</script>', ['Content-Type' => 'image/png']);
        $this->http->respond('GET', 'https://alice.example/vector', 200, '<svg/>', ['Content-Type' => 'image/svg+xml']);

        $archiver = $this->archiver();
        self::assertSame('https://alice.example/page', $archiver->archive('https://alice.example/page'));
        self::assertSame('https://alice.example/fake.png', $archiver->archive('https://alice.example/fake.png'));
        self::assertSame('https://alice.example/vector', $archiver->archive('https://alice.example/vector'));
        self::assertSame('https://alice.example/gone.png', $archiver->archive('https://alice.example/gone.png'));
        self::assertSame([], array_filter($this->http->requests, static fn (array $r): bool => str_contains($r['url'], 'amazonaws')));
    }

    public function testOriginalUrlIsKeptWhenTheUploadFails(): void
    {
        $png = self::png(10, 10);
        $this->http->respond('GET', self::PHOTO, 200, $png, ['Content-Type' => 'image/png']);
        $this->http->respond('PUT', self::BUCKET . 'alice.example/' . hash('sha256', $png) . '.png', 403, '<Error>AccessDenied</Error>');

        self::assertSame(self::PHOTO, $this->archiver()->archive(self::PHOTO));
    }

    public function testSvgAvatarsAndUnconfiguredArchivingMakeNoRequests(): void
    {
        $archiver = $this->archiver();
        self::assertSame('https://alice.example/me.SVG', $archiver->archive('https://alice.example/me.SVG'));
        self::assertSame('https://alice.example/avatar.svg?v=2', $archiver->archive('https://alice.example/avatar.svg?v=2'));

        self::assertSame(self::PHOTO, $this->archiver(['AVATAR_S3_BUCKET' => ''])->archive(self::PHOTO));
        self::assertSame([], $this->http->requests);
    }

    /** @param array<string, string> $settings */
    private function archiver(array $settings = []): AvatarArchiver
    {
        $config = new Config([
            'BASE_URL'             => 'https://webmention.io',
            'AVATAR_S3_BUCKET'     => 'bucket',
            'AVATAR_S3_REGION'     => 'us-west-2',
            'AVATAR_S3_KEY_ID'     => 'kid',
            'AVATAR_S3_SECRET_KEY' => 'secret',
            ...$settings,
        ]);
        $client = new HttpClient($config->baseUrl(), $this->http);

        return new AvatarArchiver($config, AvatarArchiver::s3FromConfig($config, $client), $client, new Log(sys_get_temp_dir() . '/webmention-test.log'));
    }

    /** @return array{method: string, url: string, body: string|null, headers: list<string>} */
    private function request(string $method, string $key): array
    {
        foreach ($this->http->requests as $request) {
            if ($request['method'] === $method && $request['url'] === self::BUCKET . $key) {
                return $request;
            }
        }

        self::fail("No $method of $key");
    }

    /** @param list<string> $headers */
    private static function authorization(array $headers): string
    {
        foreach ($headers as $header) {
            if (str_starts_with($header, 'Authorization: ')) {
                return $header;
            }
        }

        return '';
    }

    private static function png(int $width, int $height): string
    {
        return self::encode($width, $height, static fn ($image) => imagepng($image));
    }

    private static function gif(int $width, int $height): string
    {
        return self::encode($width, $height, static fn ($image) => imagegif($image));
    }

    private static function jpeg(int $width, int $height): string
    {
        return self::encode($width, $height, static fn ($image) => imagejpeg($image));
    }

    private static function encode(int $width, int $height, callable $write): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 80, 40));
        ob_start();
        $write($image);

        return (string) ob_get_clean();
    }
}
