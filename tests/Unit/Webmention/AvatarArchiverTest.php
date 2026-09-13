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
    private const ENDPOINT = 'https://ca3db.example/archive';
    private const PHOTO    = 'https://alice.example/photo.jpg';

    public function testStoredUrlUsesTheBaseUrlByDefault(): void
    {
        [$archiver] = $this->archiver(['BASE_URL' => 'https://v2.webmention.io']);

        self::assertSame('https://v2.webmention.io/avatar/alice.example/abc.jpg', $archiver->archive(self::PHOTO));
    }

    public function testStoredUrlPrefixCanBeSetForADeploymentSharingTheDatabase(): void
    {
        [$archiver, $http] = $this->archiver([
            'BASE_URL'         => 'https://v2.webmention.io',
            'CA3DB_AVATAR_URL' => 'https://webmention.io/avatar/',
        ]);

        self::assertSame('https://webmention.io/avatar/alice.example/abc.jpg', $archiver->archive(self::PHOTO));
        self::assertSame('https://webmention.io/avatar', $archiver->avatarUrlPrefix());

        // The request to CA3DB carries the original photo URL and the credentials.
        $post = json_decode((string) $http->posts(self::ENDPOINT)[0]['body'], true);
        self::assertSame(self::PHOTO, $post['url']);
        self::assertSame('kid', $post['key_id']);
    }

    public function testUnexpectedArchiveLocationsAreStoredAsReturned(): void
    {
        [$archiver] = $this->archiver(['CA3DB_AVATAR_URL' => 'https://webmention.io/avatar'], archived: 'https://elsewhere.example/x.jpg');

        self::assertSame('https://elsewhere.example/x.jpg', $archiver->archive(self::PHOTO));
    }

    public function testOriginalUrlIsKeptWhenArchivingIsOffOrFails(): void
    {
        [$off] = $this->archiver(['CA3DB_API_ENDPOINT' => '']);
        self::assertSame(self::PHOTO, $off->archive(self::PHOTO));

        [$failing, $http] = $this->archiver([]);
        $http->respond('POST', self::ENDPOINT, 500, 'boom');
        self::assertSame(self::PHOTO, $failing->archive(self::PHOTO));
    }

    /**
     * @param  array<string, string> $settings
     * @return array{AvatarArchiver, FakeTransport}
     */
    private function archiver(array $settings, string $archived = 'https://bucket.s3.example/alice.example/abc.jpg'): array
    {
        $config = new Config([
            'BASE_URL'           => 'https://webmention.io',
            'CA3DB_API_ENDPOINT' => self::ENDPOINT,
            'CA3DB_API_KEY'      => 'apikey',
            'CA3DB_KEY_ID'       => 'kid',
            'CA3DB_SECRET_KEY'   => 'secret',
            'CA3DB_REGION'       => 'us-west-2',
            'CA3DB_BUCKET'       => 'bucket',
            'CA3DB_S3_URL'       => 'https://bucket.s3.example/',
            ...$settings,
        ]);

        $http = new FakeTransport(dirname(__DIR__, 2) . '/fixtures');
        $http->respond('POST', self::ENDPOINT, 200, json_encode(['url' => $archived]), ['Content-Type' => 'application/json']);

        $client = new HttpClient($config->baseUrl(), $http);

        return [new AvatarArchiver($config, $client, new Log(sys_get_temp_dir() . '/webmention-test.log')), $http];
    }
}
