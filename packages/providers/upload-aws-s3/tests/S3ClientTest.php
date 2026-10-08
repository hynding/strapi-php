<?php

declare(strict_types=1);

namespace Strapi\Provider\UploadAwsS3\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Provider\UploadAwsS3\Checksum;
use Strapi\Provider\UploadAwsS3\S3Client;
use Strapi\Provider\UploadAwsS3\S3ServiceException;
use Strapi\Provider\UploadAwsS3\SignatureV4;
use Strapi\Provider\UploadAwsS3\UploadAwsS3;

/**
 * PHP-port addition: the S3 client (the AWS SDK's part) at the HTTP level, with `strapi.fetch`
 * replaced by a recorder — no network.
 */
final class S3ClientTest extends TestCase
{
    private const array CREDENTIALS = ['accessKeyId' => 'AKIAIOSFODNN7EXAMPLE', 'secretAccessKey' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'];

    /** @var list<array{url: string, method: string, headers: array<string, string>, body: string|null}> */
    private array $requests = [];

    /** @var list<array{status: int, headers?: array<string, string>, body?: string}> */
    private array $responses = [];

    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->requests = [];
        $this->responses = [];
        foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SESSION_TOKEN', 'AWS_REGION', 'AWS_REQUEST_CHECKSUM_CALCULATION'] as $name) {
            $this->env[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    /** @param array<string, mixed> $config */
    private function client(array $config = []): S3Client
    {
        $fetch = function (string $url, array $options): array {
            $this->requests[] = ['url' => $url, 'method' => $options['method'] ?? 'GET', 'headers' => $options['headers'] ?? [], 'body' => $options['body'] ?? null];
            $response = array_shift($this->responses) ?? ['status' => 200];

            return ['ok' => $response['status'] < 300, 'status' => $response['status'], 'headers' => $response['headers'] ?? [], 'body' => $response['body'] ?? ''];
        };

        return new S3Client(
            ['region' => 'eu-west-1', 'credentials' => self::CREDENTIALS, ...$config],
            $fetch,
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2024-01-02T03:04:05Z'),
        );
    }

    /** Recomputes the Authorization header from what was sent: the request is verifiable by S3. */
    private static function assertSignedRequest(array $request, string $region = 'eu-west-1'): void
    {
        $headers = $request['headers'];
        preg_match('~SignedHeaders=([^,]+),~', $headers['authorization'], $m);
        $signedNames = explode(';', $m[1]);
        $signed = array_intersect_key($headers, array_flip($signedNames));
        unset($signed['x-amz-date']);
        self::assertSame(hash('sha256', $request['body'] ?? ''), $headers['x-amz-content-sha256']);

        $expected = (new SignatureV4('s3', $region))->sign($request['method'], $request['url'], $signed, $headers['x-amz-content-sha256'], self::CREDENTIALS, new \DateTimeImmutable('2024-01-02T03:04:05Z'));
        self::assertSame($expected['authorization'], $headers['authorization']);
    }

    public function testSinglePartUploadIsAPutObject(): void
    {
        $this->responses[] = ['status' => 200, 'headers' => ['ETag' => '"etag-1"']];

        $result = $this->client()->upload(['params' => [
            'Bucket' => 'my-bucket', 'Key' => 'uploads/a b+c.png', 'Body' => 'hello', 'ACL' => 'public-read',
            'ContentType' => 'image/png', 'StorageClass' => 'STANDARD_IA', 'Tagging' => 'a=b', 'IfNoneMatch' => '*',
            'ContentDisposition' => 'attachment', 'Metadata' => ['Owner' => 'me'],
        ]]);

        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame('PUT', $request['method']);
        self::assertSame('https://my-bucket.s3.eu-west-1.amazonaws.com/uploads/a%20b%2Bc.png', $request['url']);
        self::assertSame('hello', $request['body']);
        self::assertSame('my-bucket.s3.eu-west-1.amazonaws.com', $request['headers']['host']);
        self::assertSame('public-read', $request['headers']['x-amz-acl']);
        self::assertSame('image/png', $request['headers']['content-type']);
        self::assertSame('STANDARD_IA', $request['headers']['x-amz-storage-class']);
        self::assertSame('a=b', $request['headers']['x-amz-tagging']);
        self::assertSame('*', $request['headers']['if-none-match']);
        self::assertSame('attachment', $request['headers']['content-disposition']);
        self::assertSame('me', $request['headers']['x-amz-meta-owner']);
        self::assertSame('5', $request['headers']['content-length']);
        // requestChecksumCalculation WHEN_SUPPORTED (the SDK default): CRC32
        self::assertSame('CRC32', $request['headers']['x-amz-sdk-checksum-algorithm']);
        self::assertSame(base64_encode(hash('crc32b', 'hello', true)), $request['headers']['x-amz-checksum-crc32']);
        self::assertSignedRequest($request);

        self::assertSame('https://my-bucket.s3.eu-west-1.amazonaws.com/uploads/a%20b%2Bc.png', $result['Location']);
        self::assertSame('"etag-1"', $result['ETag']);
        self::assertSame(['httpStatusCode' => 200], $result['$metadata']);
    }

    public function testStreamBodiesAndConfiguredChecksums(): void
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertNotFalse($stream);
        fwrite($stream, '123456789');
        rewind($stream);

        $this->client()->upload(['params' => ['Bucket' => 'bkt', 'Key' => 'k', 'Body' => $stream, 'ChecksumAlgorithm' => 'CRC64NVME']]);

        self::assertSame('123456789', $this->requests[0]['body']);
        self::assertSame('CRC64NVME', $this->requests[0]['headers']['x-amz-sdk-checksum-algorithm']);
        self::assertSame(base64_encode(hex2bin('ae8b14860a799888') ?: ''), $this->requests[0]['headers']['x-amz-checksum-crc64nvme']);
    }

    public function testWhenRequiredDisablesTheDefaultChecksum(): void
    {
        $this->client(['requestChecksumCalculation' => 'WHEN_REQUIRED'])->upload(['params' => ['Bucket' => 'bkt', 'Key' => 'k', 'Body' => 'x']]);

        self::assertArrayNotHasKey('x-amz-sdk-checksum-algorithm', $this->requests[0]['headers']);
        self::assertArrayNotHasKey('x-amz-checksum-crc32', $this->requests[0]['headers']);
    }

    public function testChecksumVectors(): void
    {
        self::assertSame('ae8b14860a799888', sprintf('%016x', Checksum::crc64nvme('123456789')));
        self::assertSame(base64_encode(hex2bin('cbf43926') ?: ''), Checksum::compute('CRC32', '123456789'));
        self::assertSame(base64_encode(hex2bin('e3069283') ?: ''), Checksum::compute('CRC32C', '123456789'));
        self::assertSame(base64_encode(sha1('123456789', true)), Checksum::compute('SHA1', '123456789'));
        self::assertSame(base64_encode(hash('sha256', '123456789', true)), Checksum::compute('SHA256', '123456789'));
    }

    public function testForcePathStyleWithACustomEndpoint(): void
    {
        $client = $this->client(['endpoint' => 'http://minio.local:9000', 'forcePathStyle' => true, 'region' => 'us-east-1']);
        $result = $client->upload(['params' => ['Bucket' => 'test-bucket', 'Key' => 'tmp/test.json', 'Body' => '{}']]);

        self::assertSame('http://minio.local:9000/test-bucket/tmp/test.json', $this->requests[0]['url']);
        self::assertSame('minio.local:9000', $this->requests[0]['headers']['host']);
        self::assertSame('http://minio.local:9000/test-bucket/tmp/test.json', $result['Location']);
        self::assertSignedRequest($this->requests[0], 'us-east-1');
    }

    public function testCustomEndpointsAreVirtualHostedUnlessTheBucketOrHostForbidsIt(): void
    {
        self::assertSame('https://media.acc.r2.cloudflarestorage.com/a.png', $this->client(['endpoint' => 'https://acc.r2.cloudflarestorage.com', 'region' => 'auto'])->urlOf('media', 'a.png'));
        self::assertSame('https://s3.wasabisys.com/b/a.png', $this->client(['endpoint' => 's3.wasabisys.com', 'forcePathStyle' => true])->urlOf('b', 'a.png'));
        self::assertSame('https://s3.eu-west-1.amazonaws.com/my.dotted.bucket/a.png', $this->client()->urlOf('my.dotted.bucket', 'a.png'));
        self::assertSame('http://127.0.0.1:9000/b/a.png', $this->client(['endpoint' => 'http://127.0.0.1:9000'])->urlOf('b', 'a.png'));
        self::assertSame('https://bkt.s3.cn-north-1.amazonaws.com.cn/a.png', $this->client(['region' => 'cn-north-1'])->urlOf('bkt', 'a.png'));
    }

    public function testMultipartUploadForBodiesLargerThanOnePart(): void
    {
        $partSize = S3Client::MIN_PART_SIZE;
        $body = str_repeat('a', $partSize) . 'tail';
        $this->responses = [
            ['status' => 200, 'body' => '<InitiateMultipartUploadResult><Bucket>bkt</Bucket><Key>big.bin</Key><UploadId>up/1</UploadId></InitiateMultipartUploadResult>'],
            ['status' => 200, 'headers' => ['ETag' => '"p1"']],
            ['status' => 200, 'headers' => ['ETag' => '"p2"']],
            ['status' => 200, 'body' => '<CompleteMultipartUploadResult><Location>https://bkt.s3.eu-west-1.amazonaws.com/big.bin</Location><Bucket>bkt</Bucket><Key>big.bin</Key><ETag>"final-2"</ETag></CompleteMultipartUploadResult>'],
        ];

        $result = $this->client()->upload(['params' => [
            'Bucket' => 'bkt', 'Key' => 'big.bin', 'Body' => $body, 'ContentType' => 'application/octet-stream',
            'ACL' => 'private', 'ChecksumAlgorithm' => 'SHA256', 'IfNoneMatch' => '*',
        ], 'partSize' => $partSize]);

        self::assertSame(['POST', 'PUT', 'PUT', 'POST'], array_column($this->requests, 'method'));
        [$create, $part1, $part2, $complete] = $this->requests;

        self::assertSame('https://bkt.s3.eu-west-1.amazonaws.com/big.bin?uploads', $create['url']);
        self::assertSame('private', $create['headers']['x-amz-acl']);
        self::assertSame('SHA256', $create['headers']['x-amz-checksum-algorithm']);
        self::assertArrayNotHasKey('if-none-match', $create['headers']);

        self::assertSame('https://bkt.s3.eu-west-1.amazonaws.com/big.bin?partNumber=1&uploadId=up%2F1', $part1['url']);
        self::assertSame($partSize, strlen((string) $part1['body']));
        self::assertSame('https://bkt.s3.eu-west-1.amazonaws.com/big.bin?partNumber=2&uploadId=up%2F1', $part2['url']);
        self::assertSame('tail', $part2['body']);
        self::assertSame(base64_encode(hash('sha256', 'tail', true)), $part2['headers']['x-amz-checksum-sha256']);

        self::assertSame('https://bkt.s3.eu-west-1.amazonaws.com/big.bin?uploadId=up%2F1', $complete['url']);
        self::assertSame('*', $complete['headers']['if-none-match']);
        self::assertStringContainsString('<Part><ETag>"p1"</ETag><PartNumber>1</PartNumber><ChecksumSHA256>', (string) $complete['body']);
        self::assertStringContainsString('<Part><ETag>"p2"</ETag><PartNumber>2</PartNumber><ChecksumSHA256>' . base64_encode(hash('sha256', 'tail', true)) . '</ChecksumSHA256></Part>', (string) $complete['body']);
        foreach ($this->requests as $request) {
            self::assertSignedRequest($request);
        }

        self::assertSame('https://bkt.s3.eu-west-1.amazonaws.com/big.bin', $result['Location']);
        self::assertSame('"final-2"', $result['ETag']);
    }

    public function testAFailedMultipartUploadIsAbortedUnlessLeavePartsOnError(): void
    {
        $body = str_repeat('a', S3Client::MIN_PART_SIZE + 1);
        $initiate = ['status' => 200, 'body' => '<InitiateMultipartUploadResult><UploadId>u1</UploadId></InitiateMultipartUploadResult>'];
        $failure = ['status' => 500, 'body' => '<Error><Code>InternalError</Code><Message>We encountered an internal error.</Message><RequestId>r1</RequestId></Error>'];

        $this->responses = [$initiate, $failure, ['status' => 204]];
        try {
            $this->client()->upload(['params' => ['Bucket' => 'bkt', 'Key' => 'k', 'Body' => $body]]);
            self::fail('expected an error');
        } catch (S3ServiceException $error) {
            self::assertSame('InternalError', $error->name);
            self::assertSame('We encountered an internal error.', $error->getMessage());
            self::assertSame(['httpStatusCode' => 500, 'requestId' => 'r1'], $error->metadata);
        }
        self::assertSame('DELETE', $this->requests[2]['method']);
        self::assertSame('https://bkt.s3.eu-west-1.amazonaws.com/k?uploadId=u1', $this->requests[2]['url']);

        $this->requests = [];
        $this->responses = [$initiate, $failure];
        try {
            $this->client()->upload(['params' => ['Bucket' => 'bkt', 'Key' => 'k', 'Body' => $body], 'leavePartsOnError' => true]);
            self::fail('expected an error');
        } catch (S3ServiceException) {
        }
        self::assertCount(2, $this->requests);
    }

    public function testCompleteMultipartUploadErrorsReturnedWithA200AreThrown(): void
    {
        $this->responses = [
            ['status' => 200, 'body' => '<InitiateMultipartUploadResult><UploadId>u1</UploadId></InitiateMultipartUploadResult>'],
            ['status' => 200, 'headers' => ['etag' => '"p1"']],
            ['status' => 200, 'headers' => ['etag' => '"p2"']],
            ['status' => 200, 'body' => '<Error><Code>InvalidPart</Code><Message>One or more of the specified parts could not be found.</Message></Error>'],
        ];

        $this->expectException(S3ServiceException::class);
        $this->expectExceptionMessage('One or more of the specified parts could not be found.');

        $this->client()->upload(['params' => ['Bucket' => 'bkt', 'Key' => 'k', 'Body' => str_repeat('a', S3Client::MIN_PART_SIZE + 1)]]);
    }

    public function testPartSizeBelowTheMinimumIsRejected(): void
    {
        $this->expectExceptionMessage('EntityTooSmall');

        $this->client()->upload(['params' => ['Bucket' => 'bkt', 'Key' => 'k', 'Body' => 'x'], 'partSize' => 1024]);
    }

    public function testDeleteObject(): void
    {
        $this->responses[] = ['status' => 204, 'headers' => ['x-amz-delete-marker' => 'true', 'x-amz-version-id' => 'v2']];

        $result = $this->client()->send('DeleteObject', ['Bucket' => 'bkt', 'Key' => 'dir/a.png', 'VersionId' => 'v1']);

        self::assertSame('DELETE', $this->requests[0]['method']);
        self::assertSame('https://bkt.s3.eu-west-1.amazonaws.com/dir/a.png?versionId=v1', $this->requests[0]['url']);
        self::assertNull($this->requests[0]['body']);
        self::assertSignedRequest($this->requests[0]);
        self::assertSame(['DeleteMarker' => true, 'VersionId' => 'v2', '$metadata' => ['httpStatusCode' => 204]], $result);
    }

    public function testHeadObject(): void
    {
        $this->responses[] = ['status' => 200, 'headers' => [
            'ETag' => '"e"', 'Content-Length' => '1024', 'Content-Type' => 'application/json',
            'Last-Modified' => 'Mon, 01 Jan 2024 00:00:00 GMT', 'x-amz-storage-class' => 'STANDARD_IA',
            'x-amz-server-side-encryption' => 'AES256', 'x-amz-meta-owner' => 'me',
        ]];

        $result = $this->client()->send('HeadObject', ['Bucket' => 'bkt', 'Key' => 'k']);

        self::assertSame('HEAD', $this->requests[0]['method']);
        self::assertSame('"e"', $result['ETag']);
        self::assertSame(1024, $result['ContentLength']);
        self::assertSame('application/json', $result['ContentType']);
        self::assertInstanceOf(\DateTimeImmutable::class, $result['LastModified']);
        self::assertSame('2024-01-01', $result['LastModified']->format('Y-m-d'));
        self::assertSame('STANDARD_IA', $result['StorageClass']);
        self::assertSame('AES256', $result['ServerSideEncryption']);
        self::assertSame(['owner' => 'me'], $result['Metadata']);
    }

    public function testABodyLess404IsNamedNotFoundAndObjectExistsReturnsFalse(): void
    {
        $this->responses[] = ['status' => 404];
        try {
            $this->client()->send('HeadObject', ['Bucket' => 'bkt', 'Key' => 'missing']);
            self::fail('expected an error');
        } catch (S3ServiceException $error) {
            self::assertSame('NotFound', $error->name);
            self::assertSame(404, $error->status);
        }

        $this->responses[] = ['status' => 404];
        $provider = UploadAwsS3::init(['s3Options' => ['params' => ['Bucket' => 'bkt']]], null, fn (): S3Client => $this->client());
        self::assertFalse($provider->objectExists(['hash' => 'missing']));
    }

    public function testPresignGetObject(): void
    {
        $url = $this->client()->presignGetObject(['Bucket' => 'bkt', 'Key' => 'tmp/a b.png', 'ResponseContentDisposition' => 'attachment; filename="a.png"'], 900);

        $parts = parse_url($url);
        self::assertSame('bkt.s3.eu-west-1.amazonaws.com', $parts['host'] ?? null);
        self::assertSame('/tmp/a%20b.png', $parts['path'] ?? null);
        parse_str($parts['query'] ?? '', $query);
        self::assertSame('AWS4-HMAC-SHA256', $query['X-Amz-Algorithm']);
        self::assertSame('AKIAIOSFODNN7EXAMPLE/20240102/eu-west-1/s3/aws4_request', $query['X-Amz-Credential']);
        self::assertSame('20240102T030405Z', $query['X-Amz-Date']);
        self::assertSame('900', $query['X-Amz-Expires']);
        self::assertSame('host', $query['X-Amz-SignedHeaders']);
        self::assertSame('UNSIGNED-PAYLOAD', $query['X-Amz-Content-Sha256']);
        self::assertSame('GetObject', $query['x-id']);
        self::assertSame('attachment; filename="a.png"', $query['response-content-disposition']);

        // the signature covers every other query parameter
        $unsigned = (string) preg_replace('~&X-Amz-Signature=[0-9a-f]{64}$~', '', $url);
        $base = (string) preg_replace('~&X-Amz-Algorithm=.*$~', '', $unsigned);
        $resigned = (new SignatureV4('s3', 'eu-west-1'))->presign('GET', $base, self::CREDENTIALS, 900, new \DateTimeImmutable('2024-01-02T03:04:05Z'));
        self::assertSame($url, $resigned);
        self::assertSame([], $this->requests, 'presigning makes no request');
    }

    public function testCredentialsAndRegionFromTheEnvironment(): void
    {
        putenv('AWS_ACCESS_KEY_ID=ENVKEY');
        putenv('AWS_SECRET_ACCESS_KEY=ENVSECRET');
        putenv('AWS_SESSION_TOKEN=ENVTOKEN');
        putenv('AWS_REGION=ap-south-1');

        $client = new S3Client([], fn (string $url, array $options): array => $this->record($url, $options));
        $client->send('DeleteObject', ['Bucket' => 'bkt', 'Key' => 'k']);

        $headers = $this->requests[0]['headers'];
        self::assertSame('https://bkt.s3.ap-south-1.amazonaws.com/k', $this->requests[0]['url']);
        self::assertStringContainsString('Credential=ENVKEY/', $headers['authorization']);
        self::assertSame('ENVTOKEN', $headers['x-amz-security-token']);
    }

    public function testACredentialProviderIsResolvedOnEveryRequest(): void
    {
        $calls = 0;
        $client = $this->client(['credentials' => static function () use (&$calls): array {
            ++$calls;

            return ['accessKeyId' => "KEY{$calls}", 'secretAccessKey' => 'secret'];
        }]);

        $client->send('DeleteObject', ['Bucket' => 'bkt', 'Key' => 'k']);
        $client->send('DeleteObject', ['Bucket' => 'bkt', 'Key' => 'k']);

        self::assertStringContainsString('Credential=KEY1/', $this->requests[0]['headers']['authorization']);
        self::assertStringContainsString('Credential=KEY2/', $this->requests[1]['headers']['authorization']);
    }

    public function testMissingCredentialsAndRegion(): void
    {
        try {
            (new S3Client(['region' => 'eu-west-1'], fn (string $url, array $options): array => $this->record($url, $options)))->send('DeleteObject', ['Bucket' => 'bkt', 'Key' => 'k']);
            self::fail('expected an error');
        } catch (\RuntimeException $error) {
            self::assertSame('Could not load credentials from any providers', $error->getMessage());
        }

        $this->expectExceptionMessage('Region is missing');
        (new S3Client(['credentials' => self::CREDENTIALS], fn (string $url, array $options): array => $this->record($url, $options)))->send('DeleteObject', ['Bucket' => 'bkt', 'Key' => 'k']);
    }

    public function testTheProviderUsesStrapiFetchEndToEnd(): void
    {
        $strapi = $this->createMock(\Strapi\Types\Core\Strapi::class);
        $strapi->method('has')->willReturnCallback(static fn (string $name): bool => $name === 'fetch');
        $strapi->method('get')->willReturnCallback(fn (string $name): mixed => $name === 'fetch' ? fn (string $url, array $options): array => $this->record($url, $options) : null);

        $provider = UploadAwsS3::init([
            's3Options' => ['region' => 'eu-west-1', 'credentials' => self::CREDENTIALS, 'params' => ['Bucket' => 'my-bucket']],
        ], $strapi);

        $file = new \ArrayObject(['hash' => 'abc', 'ext' => '.png', 'mime' => 'image/png', 'buffer' => 'png!']);
        $provider->upload($file);

        self::assertSame('https://my-bucket.s3.eu-west-1.amazonaws.com/abc.png', $file['url']);
        self::assertSame('https://my-bucket.s3.eu-west-1.amazonaws.com/abc.png', $this->requests[0]['url']);
        self::assertSame('public-read', $this->requests[0]['headers']['x-amz-acl']);
        self::assertSame('image/png', $this->requests[0]['headers']['content-type']);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{ok: bool, status: int, headers: array<string, string>, body: string}
     */
    private function record(string $url, array $options): array
    {
        /** @var array<string, string> $headers */
        $headers = $options['headers'] ?? [];
        $body = $options['body'] ?? null;
        $method = $options['method'] ?? 'GET';
        $this->requests[] = ['url' => $url, 'method' => is_string($method) ? $method : 'GET', 'headers' => $headers, 'body' => is_string($body) ? $body : null];

        return ['ok' => true, 'status' => 200, 'headers' => ['etag' => '"x"'], 'body' => ''];
    }
}
