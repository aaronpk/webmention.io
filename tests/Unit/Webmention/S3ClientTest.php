<?php

declare(strict_types=1);

namespace Webmention\Tests\Unit\Webmention;

use PHPUnit\Framework\TestCase;
use Webmention\Tests\Support\FakeTransport;
use Webmention\Webmention\HttpClient;
use Webmention\Webmention\S3Client;

final class S3ClientTest extends TestCase
{
    /**
     * The "GET Object" example from AWS's Signature Version 4 documentation
     * (Authenticating Requests: Using the Authorization Header).
     */
    public function testMatchesTheAwsSigningExample(): void
    {
        $s3 = new S3Client(
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1',
            'examplebucket',
            new HttpClient('https://webmention.io', new FakeTransport(sys_get_temp_dir())),
            static fn (): int => gmmktime(0, 0, 0, 5, 24, 2013),
        );

        $headers = $s3->signedHeaders('GET', 'test.txt', ['range' => 'bytes=0-9'], hash('sha256', ''));

        self::assertSame('examplebucket.s3.amazonaws.com', $s3->host());
        self::assertContains(
            'Authorization: AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $headers,
        );
        self::assertContains('x-amz-date: 20130524T000000Z', $headers);
    }
}
