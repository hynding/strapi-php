<?php

declare(strict_types=1);

namespace Strapi\Provider\UploadAwsS3\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Provider\UploadAwsS3\Utils;

/** Port of src/__tests__/is-url-from-bucket.vitest.test.ts. */
final class IsUrlFromBucketTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, bool}> */
    public static function urls(): iterable
    {
        // AWS
        yield 'AWS virtual hosted style' => ['https://bucket-name-123.s3.us-east-1.amazonaws.com/img.png', 'bucket-name-123', '', true];
        yield 'AWS path style, no key' => ['https://s3.us-east-1.amazonaws.com/bucket', 'bucket', '', true];
        yield 'AWS path style, with trailing slash' => ['https://s3.us-east-1.amazonaws.com/bucket/', 'bucket', '', true];
        yield 'AWS path style, with key' => ['https://s3.us-east-1.amazonaws.com/bucket/img.png', 'bucket', '', true];
        yield 'AWS S3 access point' => ['https://bucket.s3-accesspoint.us-east-1.amazonaws.com', 'bucket', '', true];
        yield 'AWS S3://' => ['S3://bucket/img.png', 'bucket', '', true];

        // S3 Compatible
        yield 'DO Spaces, is from same bucket' => ['https://bucket-name.nyc3.digitaloceanspaces.com/folder/img.png', 'bucket-name', '', true];
        yield 'DO Spaces, is not from same bucket' => ['https://bucket-name.nyc3.digitaloceanspaces.com/folder/img.png', 'bucket', '', false];
        yield 'MinIO, is from same bucket' => ['https://minio.example.com/bucket-name/folder/file', 'bucket-name', '', true];
        yield 'MinIO, is not from same bucket' => ['https://minio.example.com/bucket-name/folder/file', 'bucket', '', false];

        yield 'CDN' => ['https://cdn.example.com/v1/img.png', 'bucket', 'https://cdn.example.com/v1/', false];

        // Malformed URLs
        yield 'returns false for invalid URL string' => ['not-a-url', 'bucket', '', false];
        yield 'returns false for empty string' => ['', 'bucket', '', false];
        yield 'returns false for broken protocol' => ['://broken', 'bucket', '', false];
    }

    #[DataProvider('urls')]
    public function testIsUrlFromBucket(string $url, string $bucket, string $baseUrl, bool $expected): void
    {
        self::assertSame($expected, Utils::isUrlFromBucket($url, $bucket, $baseUrl));
    }
}
