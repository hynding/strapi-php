<?php

declare(strict_types=1);

namespace Strapi\Provider\UploadAwsS3\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Provider\UploadAwsS3\S3Client;
use Strapi\Provider\UploadAwsS3\S3ServiceException;
use Strapi\Provider\UploadAwsS3\UploadAwsS3;
use Strapi\Provider\UploadAwsS3\Utils;

/**
 * Port of src/__tests__/upload-aws-s3.vitest.test.ts.
 *
 * Upstream mocks `S3Client` and `@aws-sdk/lib-storage`'s `Upload`; here `init()`'s third argument
 * hands the provider a fake {@see S3Client} whose `upload()` (the `Upload` options:
 * `{ params, partSize, queueSize, leavePartsOnError }`) and `send()` are recorded and scripted.
 * The HTTP behaviour of the real client is covered by S3ClientTest.
 */
final class UploadAwsS3Test extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->warnings = [];
        Utils::$warningHandler = function (string $message): void {
            $this->warnings[] = $message;
        };
    }

    protected function tearDown(): void
    {
        Utils::$warningHandler = null;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: UploadAwsS3, 1: FakeS3Client}
     */
    private function init(array $options): array
    {
        $client = null;
        $provider = UploadAwsS3::init($options, null, static function (array $config) use (&$client): S3Client {
            return $client = new FakeS3Client($config);
        });
        self::assertInstanceOf(FakeS3Client::class, $client);

        return [$provider, $client];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return \ArrayObject<string, mixed>
     */
    private static function createTestFile(array $overrides = []): \ArrayObject
    {
        return new \ArrayObject([
            'name' => 'test',
            'size' => 100,
            'sizeInBytes' => 100,
            'url' => '',
            'path' => 'tmp',
            'hash' => 'test',
            'ext' => '.json',
            'mime' => 'application/json',
            'buffer' => 'test content',
            ...$overrides,
        ]);
    }

    /** @return array{params: array<string, mixed>, partSize?: int, queueSize?: int, leavePartsOnError?: bool} */
    private static function uploadCall(FakeS3Client $client): array
    {
        self::assertNotEmpty($client->uploadCalls, 'Upload was not called');

        return $client->uploadCalls[0];
    }

    // ---------------------------------------------------------------- upload

    public function testShouldPopulateFileUrlWithTheBaseUrlWhenProvidedAppendingTheFileKeyToTheUrl(): void
    {
        [$provider, $client] = $this->init([
            'baseUrl' => 'https://assets.strapi.io',
            's3Options' => ['params' => ['Bucket' => 'bucket-name']],
        ]);
        $client->uploadResults[] = ['Location' => 'https://strapi.io/path/to/fileNameHash.json', '$metadata' => []];

        $file = new \ArrayObject([
            'name' => 'fileName', 'size' => 100, 'sizeInBytes' => 100 * 1024, 'url' => '', 'path' => 'path/to',
            'hash' => 'fileNameHash', 'ext' => '.json', 'mime' => 'application/json', 'buffer' => '',
        ]);

        $provider->upload($file);

        self::assertCount(1, $client->uploadCalls);
        self::assertSame('https://assets.strapi.io/path/to/fileNameHash.json', $file['url']);
    }

    public function testShouldPopulateFileUrlWithTheReturnedLocationWhenNoBaseUrlIsProvided(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'bucket-name']]]);
        $client->uploadResults[] = ['Location' => 'https://strapi.io/path/from/location/fileName.json', '$metadata' => []];

        $file = new \ArrayObject([
            'name' => 'fileName', 'size' => 100, 'sizeInBytes' => 100 * 1024, 'url' => '', 'path' => 'path/from/location',
            'hash' => 'fileNameHash', 'ext' => '.json', 'mime' => 'application/json', 'buffer' => '',
        ]);

        $provider->upload($file);

        self::assertCount(1, $client->uploadCalls);
        self::assertSame('https://strapi.io/path/from/location/fileName.json', $file['url']);
    }

    public function testShouldPopulateFileUrlAndPrependTheHttpsProtocolToTheLocationWhenMissing(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'bucket-name']]]);
        $client->uploadResults[] = ['Location' => 'strapi.io/path/to/fileNameHash.json', '$metadata' => []];

        $file = new \ArrayObject([
            'name' => 'fileName', 'size' => 100, 'sizeInBytes' => 100 * 1024, 'url' => '', 'path' => 'path/to',
            'hash' => 'fileNameHash', 'ext' => '.json', 'mime' => 'application/json', 'buffer' => '',
        ]);

        $provider->upload($file);

        self::assertSame('https://strapi.io/path/to/fileNameHash.json', $file['url']);
    }

    public function testShouldPopulateFileUrlWithBaseUrlEvenIfLocationLacksProtocol(): void
    {
        [$provider, $client] = $this->init([
            'baseUrl' => 'https://cdn.test',
            's3Options' => ['region' => 'test', 'params' => ['Bucket' => 'test']],
        ]);
        $client->uploadResults[] = ['Location' => 'otherdomain.com/different/path/file.json', '$metadata' => []];

        $file = self::createTestFile(['path' => 'tmp/test', 'buffer' => '']);
        $provider->upload($file);

        self::assertSame('https://cdn.test/tmp/test/test.json', $file['url']);
    }

    public function testShouldPopulateFileUrlWithBaseUrlAndRootPath(): void
    {
        [$provider, $client] = $this->init([
            'baseUrl' => 'https://cdn.test',
            'rootPath' => 'dir/dir2',
            's3Options' => ['params' => ['Bucket' => 'test']],
        ]);
        $client->uploadResults[] = ['Location' => 'https://validurl.test/tmp/test.json', '$metadata' => []];

        $file = self::createTestFile(['path' => 'tmp/test', 'buffer' => '']);
        $provider->upload($file);

        self::assertSame('https://cdn.test/dir/dir2/tmp/test/test.json', $file['url']);
    }

    public function testShouldHandleMissingLocationInUploadResponse(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);
        $client->uploadResults[] = ['$metadata' => []];

        $file = self::createTestFile();
        $provider->upload($file);

        self::assertSame('https://test.s3.amazonaws.com/tmp/test.json', $file['url']);
    }

    public function testShouldStoreETagFromUploadResponse(): void
    {
        [$provider] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);

        $file = self::createTestFile();
        $provider->upload($file);

        self::assertSame('abc123def456', $file['etag']);
    }

    public function testUploadStreamSendsTheStreamAsBody(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);

        $stream = fopen('php://memory', 'r+b');
        self::assertNotFalse($stream);
        fwrite($stream, 'streamed');
        rewind($stream);

        $file = self::createTestFile(['buffer' => null, 'stream' => $stream]);
        $provider->uploadStream($file);

        self::assertSame($stream, self::uploadCall($client)['params']['Body']);
        self::assertSame('https://validurl.test/tmp/test.json', $file['url']);
    }

    // ---------------------------------------------------------------- isPrivate

    public function testShouldReturnTrueWhenAclIsPrivate(): void
    {
        [$provider] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test', 'ACL' => 'private']]]);

        self::assertTrue($provider->isPrivate());
    }

    public function testShouldReturnFalseWhenAclIsPublic(): void
    {
        [$provider] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test', 'ACL' => 'public-read']]]);

        self::assertFalse($provider->isPrivate());
    }

    public function testAclDefaultsToPublicReadAndNullDisablesIt(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);
        $provider->upload(self::createTestFile());
        self::assertSame('public-read', self::uploadCall($client)['params']['ACL']);

        // R2 & co: an explicit null ACL (upstream: `ACL: undefined`) sends no ACL header
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test', 'ACL' => null]]]);
        $provider->upload(self::createTestFile());
        self::assertArrayNotHasKey('ACL', self::uploadCall($client)['params']);
    }

    public function testParamsAreRequired(): void
    {
        $this->expectExceptionMessage('Upload AWS S3 provider: `params` are required in the config object');

        $this->init(['s3Options' => ['region' => 'eu-west-1']]);
    }

    // ---------------------------------------------------------------- checksum validation

    /** @return iterable<string, array{string}> */
    public static function checksumAlgorithms(): iterable
    {
        yield 'CRC32' => ['CRC32'];
        yield 'SHA256' => ['SHA256'];
        yield 'CRC64NVME' => ['CRC64NVME'];
    }

    #[DataProvider('checksumAlgorithms')]
    public function testShouldIncludeChecksumAlgorithmWhenConfigured(string $algorithm): void
    {
        [$provider, $client] = $this->init([
            's3Options' => ['params' => ['Bucket' => 'test']],
            'providerConfig' => ['checksumAlgorithm' => $algorithm],
        ]);

        $provider->upload(self::createTestFile());

        self::assertSame($algorithm, self::uploadCall($client)['params']['ChecksumAlgorithm']);
    }

    public function testShouldNotIncludeChecksumAlgorithmWhenNotConfigured(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);

        $provider->upload(self::createTestFile());

        self::assertArrayNotHasKey('ChecksumAlgorithm', self::uploadCall($client)['params']);
    }

    // ---------------------------------------------------------------- conditional writes

    public function testShouldIncludeIfNoneMatchHeaderWhenPreventOverwriteIsEnabled(): void
    {
        [$provider, $client] = $this->init([
            's3Options' => ['params' => ['Bucket' => 'test']],
            'providerConfig' => ['preventOverwrite' => true],
        ]);

        $provider->upload(self::createTestFile());

        self::assertSame('*', self::uploadCall($client)['params']['IfNoneMatch']);
    }

    public function testShouldNotIncludeIfNoneMatchHeaderWhenPreventOverwriteIsDisabled(): void
    {
        [$provider, $client] = $this->init([
            's3Options' => ['params' => ['Bucket' => 'test']],
            'providerConfig' => ['preventOverwrite' => false],
        ]);

        $provider->upload(self::createTestFile());

        self::assertArrayNotHasKey('IfNoneMatch', self::uploadCall($client)['params']);
    }

    // ---------------------------------------------------------------- storage class

    /** @return iterable<string, array{string}> */
    public static function storageClasses(): iterable
    {
        yield 'STANDARD' => ['STANDARD'];
        yield 'INTELLIGENT_TIERING' => ['INTELLIGENT_TIERING'];
        yield 'GLACIER' => ['GLACIER'];
        yield 'DEEP_ARCHIVE' => ['DEEP_ARCHIVE'];
    }

    #[DataProvider('storageClasses')]
    public function testShouldSetStorageClass(string $storageClass): void
    {
        [$provider, $client] = $this->init([
            's3Options' => ['params' => ['Bucket' => 'test']],
            'providerConfig' => ['storageClass' => $storageClass],
        ]);

        $provider->upload(self::createTestFile());

        self::assertSame($storageClass, self::uploadCall($client)['params']['StorageClass']);
    }

    // ---------------------------------------------------------------- server-side encryption

    public function testShouldSetAes256Encryption(): void
    {
        [$provider, $client] = $this->init([
            's3Options' => ['params' => ['Bucket' => 'test']],
            'providerConfig' => ['encryption' => ['type' => 'AES256']],
        ]);

        $provider->upload(self::createTestFile());

        $params = self::uploadCall($client)['params'];
        self::assertSame('AES256', $params['ServerSideEncryption']);
        self::assertArrayNotHasKey('SSEKMSKeyId', $params);
    }

    public function testShouldSetKmsEncryptionWithKeyId(): void
    {
        $key = 'arn:aws:kms:us-east-1:123456789012:key/12345678-1234-1234-1234-123456789012';
        [$provider, $client] = $this->init([
            's3Options' => ['params' => ['Bucket' => 'test']],
            'providerConfig' => ['encryption' => ['type' => 'aws:kms', 'kmsKeyId' => $key]],
        ]);

        $provider->upload(self::createTestFile());

        $params = self::uploadCall($client)['params'];
        self::assertSame('aws:kms', $params['ServerSideEncryption']);
        self::assertSame($key, $params['SSEKMSKeyId']);
    }

    public function testShouldSetDsseKmsEncryption(): void
    {
        [$provider, $client] = $this->init([
            's3Options' => ['params' => ['Bucket' => 'test']],
            'providerConfig' => ['encryption' => ['type' => 'aws:kms:dsse', 'kmsKeyId' => 'test-key-id']],
        ]);

        $provider->upload(self::createTestFile());

        $params = self::uploadCall($client)['params'];
        self::assertSame('aws:kms:dsse', $params['ServerSideEncryption']);
        self::assertSame('test-key-id', $params['SSEKMSKeyId']);
    }

    // ---------------------------------------------------------------- object tagging

    public function testShouldSetSingleTag(): void
    {
        [$provider, $client] = $this->init([
            's3Options' => ['params' => ['Bucket' => 'test']],
            'providerConfig' => ['tags' => ['project' => 'test-project']],
        ]);

        $provider->upload(self::createTestFile());

        self::assertSame('project=test-project', self::uploadCall($client)['params']['Tagging']);
    }

    public function testShouldSetMultipleTags(): void
    {
        [$provider, $client] = $this->init([
            's3Options' => ['params' => ['Bucket' => 'test']],
            'providerConfig' => ['tags' => ['project' => 'test-project', 'environment' => 'production', 'team' => 'backend']],
        ]);

        $provider->upload(self::createTestFile());

        $tagging = self::uploadCall($client)['params']['Tagging'];
        self::assertIsString($tagging);
        self::assertStringContainsString('project=test-project', $tagging);
        self::assertStringContainsString('environment=production', $tagging);
        self::assertStringContainsString('team=backend', $tagging);
    }

    public function testShouldEncodeSpecialCharactersInTags(): void
    {
        [$provider, $client] = $this->init([
            's3Options' => ['params' => ['Bucket' => 'test']],
            'providerConfig' => ['tags' => ['key with spaces' => 'value with spaces']],
        ]);

        $provider->upload(self::createTestFile());

        self::assertSame('key%20with%20spaces=value%20with%20spaces', self::uploadCall($client)['params']['Tagging']);
    }

    public function testShouldNotSetTaggingWhenTagsAreEmpty(): void
    {
        [$provider, $client] = $this->init([
            's3Options' => ['params' => ['Bucket' => 'test']],
            'providerConfig' => ['tags' => []],
        ]);

        $provider->upload(self::createTestFile());

        self::assertArrayNotHasKey('Tagging', self::uploadCall($client)['params']);
    }

    // ---------------------------------------------------------------- multipart

    public function testShouldSetCustomPartSize(): void
    {
        [$provider, $client] = $this->init([
            's3Options' => ['params' => ['Bucket' => 'test']],
            'providerConfig' => ['multipart' => ['partSize' => 10 * 1024 * 1024]],
        ]);

        $provider->upload(self::createTestFile());

        self::assertSame(10 * 1024 * 1024, self::uploadCall($client)['partSize'] ?? null);
    }

    public function testShouldSetCustomQueueSize(): void
    {
        [$provider, $client] = $this->init([
            's3Options' => ['params' => ['Bucket' => 'test']],
            'providerConfig' => ['multipart' => ['queueSize' => 8]],
        ]);

        $provider->upload(self::createTestFile());

        self::assertSame(8, self::uploadCall($client)['queueSize'] ?? null);
    }

    public function testShouldSetLeavePartsOnErrorOption(): void
    {
        [$provider, $client] = $this->init([
            's3Options' => ['params' => ['Bucket' => 'test']],
            'providerConfig' => ['multipart' => ['leavePartsOnError' => true]],
        ]);

        $provider->upload(self::createTestFile());

        self::assertTrue(self::uploadCall($client)['leavePartsOnError'] ?? null);
    }

    public function testShouldSetAllMultipartOptionsTogether(): void
    {
        [$provider, $client] = $this->init([
            's3Options' => ['params' => ['Bucket' => 'test']],
            'providerConfig' => ['multipart' => ['partSize' => 5 * 1024 * 1024, 'queueSize' => 4, 'leavePartsOnError' => false]],
        ]);

        $provider->upload(self::createTestFile());

        $call = self::uploadCall($client);
        self::assertSame(5 * 1024 * 1024, $call['partSize'] ?? null);
        self::assertSame(4, $call['queueSize'] ?? null);
        self::assertFalse($call['leavePartsOnError'] ?? null);
    }

    // ---------------------------------------------------------------- uploadIfMatch

    public function testShouldIncludeIfMatchHeaderWithExpectedETag(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);

        $provider->uploadIfMatch(self::createTestFile(), 'expected-etag-123');

        self::assertSame('expected-etag-123', self::uploadCall($client)['params']['IfMatch']);
    }

    public function testShouldUpdateFileUrlAndEtagAfterSuccessfulUpload(): void
    {
        [$provider] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);

        $file = self::createTestFile();
        $provider->uploadIfMatch($file, 'expected-etag');

        self::assertSame('https://validurl.test/tmp/test.json', $file['url']);
        self::assertSame('abc123def456', $file['etag']);
    }

    // ---------------------------------------------------------------- getObjectMetadata

    public function testShouldReturnObjectMetadata(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);
        $client->sendResults[] = [
            'ETag' => '"test-etag"',
            'ContentLength' => 1024,
            'ContentType' => 'application/json',
            'LastModified' => new \DateTimeImmutable('2024-01-01'),
            'StorageClass' => 'STANDARD',
            'ServerSideEncryption' => 'AES256',
        ];

        $metadata = $provider->getObjectMetadata(self::createTestFile());

        self::assertSame('test-etag', $metadata['etag']);
        self::assertSame(1024, $metadata['contentLength']);
        self::assertSame('application/json', $metadata['contentType']);
        self::assertSame('STANDARD', $metadata['storageClass']);
        self::assertSame('AES256', $metadata['serverSideEncryption']);
        self::assertSame(['HeadObject', ['Bucket' => 'test', 'Key' => 'tmp/test.json']], $client->sendCalls[0]);
    }

    // ---------------------------------------------------------------- objectExists

    public function testShouldReturnTrueWhenObjectExists(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);
        $client->sendResults[] = ['ETag' => '"test-etag"'];

        self::assertTrue($provider->objectExists(self::createTestFile()));
    }

    public function testShouldReturnFalseWhenObjectDoesNotExistNotFound(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);
        $client->sendResults[] = new S3ServiceException('NotFound', 'Not Found', 0);

        self::assertFalse($provider->objectExists(self::createTestFile()));
    }

    public function testShouldReturnFalseWhenObjectDoesNotExist404Status(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);
        $client->sendResults[] = new S3ServiceException('UnknownError', 'Not Found', 404);

        self::assertFalse($provider->objectExists(self::createTestFile()));
    }

    public function testShouldThrowErrorForOtherErrors(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);
        $client->sendResults[] = new S3ServiceException('InternalError', 'Internal Server Error', 500);

        $this->expectExceptionMessage('Internal Server Error');

        $provider->objectExists(self::createTestFile());
    }

    // ---------------------------------------------------------------- getProviderConfig

    public function testShouldReturnProviderConfiguration(): void
    {
        $config = [
            'checksumAlgorithm' => 'SHA256',
            'preventOverwrite' => true,
            'storageClass' => 'INTELLIGENT_TIERING',
            'encryption' => ['type' => 'AES256'],
            'tags' => ['project' => 'test'],
        ];
        [$provider] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']], 'providerConfig' => $config]);

        self::assertSame($config, $provider->getProviderConfig());
    }

    public function testShouldReturnUndefinedWhenNoProviderConfig(): void
    {
        [$provider] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);

        self::assertNull($provider->getProviderConfig());
    }

    // ---------------------------------------------------------------- combined configuration

    public function testShouldApplyAllConfigurationsTogether(): void
    {
        [$provider, $client] = $this->init([
            'baseUrl' => 'https://cdn.test',
            'rootPath' => 'uploads',
            's3Options' => ['params' => ['Bucket' => 'test-bucket', 'ACL' => 'private']],
            'providerConfig' => [
                'checksumAlgorithm' => 'CRC64NVME',
                'preventOverwrite' => true,
                'storageClass' => 'STANDARD_IA',
                'encryption' => ['type' => 'aws:kms', 'kmsKeyId' => 'test-key'],
                'tags' => ['environment' => 'test'],
                'multipart' => ['partSize' => 10 * 1024 * 1024],
            ],
        ]);

        $file = self::createTestFile();
        $provider->upload($file);

        $call = self::uploadCall($client);
        $params = $call['params'];
        self::assertSame('test-bucket', $params['Bucket']);
        self::assertSame('private', $params['ACL']);
        self::assertSame('CRC64NVME', $params['ChecksumAlgorithm']);
        self::assertSame('*', $params['IfNoneMatch']);
        self::assertSame('STANDARD_IA', $params['StorageClass']);
        self::assertSame('aws:kms', $params['ServerSideEncryption']);
        self::assertSame('test-key', $params['SSEKMSKeyId']);
        self::assertSame('environment=test', $params['Tagging']);
        self::assertSame(10 * 1024 * 1024, $call['partSize'] ?? null);

        self::assertSame('https://cdn.test/uploads/tmp/test.json', $file['url']);
        self::assertTrue($provider->isPrivate());
    }

    // ---------------------------------------------------------------- security: path traversal

    public function testShouldSanitizePathTraversalSequencesInFilePath(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);

        $provider->upload(self::createTestFile(['path' => '../../../etc/passwd']));

        $key = self::uploadCall($client)['params']['Key'];
        self::assertIsString($key);
        self::assertStringNotContainsString('..', $key);
        self::assertSame('etc/passwd/test.json', $key);
    }

    public function testShouldSanitizePathTraversalSequencesInFileHash(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);

        $provider->upload(self::createTestFile(['path' => '', 'hash' => '../../../malicious']));

        self::assertSame('malicious.json', self::uploadCall($client)['params']['Key']);
    }

    public function testShouldSanitizeSpecialCharactersInFileExt(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);

        $provider->upload(self::createTestFile(['ext' => '.json;rm -rf /']));

        self::assertSame('tmp/test.jsonrmrf', self::uploadCall($client)['params']['Key']);
    }

    public function testShouldHandleMultipleConsecutiveSlashes(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);

        $provider->upload(self::createTestFile(['path' => 'foo///bar//baz']));

        self::assertSame('foo/bar/baz/test.json', self::uploadCall($client)['params']['Key']);
    }

    // ---------------------------------------------------------------- security: customParams

    public function testShouldNotAllowBucketOverrideViaCustomParamsInUpload(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'secure-bucket']]]);

        $provider->upload(self::createTestFile(), ['Bucket' => 'malicious-bucket']);

        self::assertSame('secure-bucket', self::uploadCall($client)['params']['Bucket']);
    }

    public function testShouldNotAllowKeyOverrideViaCustomParamsInUpload(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);

        $provider->upload(self::createTestFile(), ['Key' => 'malicious-key']);

        self::assertSame('tmp/test.json', self::uploadCall($client)['params']['Key']);
    }

    public function testShouldNotAllowBodyOverrideViaCustomParamsInUpload(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);

        $provider->upload(self::createTestFile(), ['Body' => 'malicious content']);

        self::assertSame('test content', self::uploadCall($client)['params']['Body']);
    }

    public function testShouldAllowSafeCustomParamsLikeContentDisposition(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);

        $provider->upload(self::createTestFile(), ['ContentDisposition' => 'attachment']);

        self::assertSame('attachment', self::uploadCall($client)['params']['ContentDisposition']);
    }

    // ---------------------------------------------------------------- security: URL protocol

    /** @return iterable<string, array{string, string}> */
    public static function locations(): iterable
    {
        yield 'https protocol' => ['https://bucket.s3.amazonaws.com/file.json', 'https://bucket.s3.amazonaws.com/file.json'];
        yield 'http protocol for S3-compatible providers' => ['http://minio.local:9000/bucket/file.json', 'http://minio.local:9000/bucket/file.json'];
        yield 'prepend https for URLs without protocol' => ['bucket.s3.amazonaws.com/file.json', 'https://bucket.s3.amazonaws.com/file.json'];
    }

    #[DataProvider('locations')]
    public function testUrlProtocolValidation(string $location, string $expected): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'test']]]);
        $client->uploadResults[] = ['Location' => $location, 'ETag' => '"abc"'];

        $file = self::createTestFile();
        $provider->upload($file);

        self::assertSame($expected, $file['url']);
    }

    // ---------------------------------------------------------------- S3-compatible URL construction

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function compatibleProviders(): iterable
    {
        yield 'should construct URL from endpoint for IONOS' => [
            ['s3Options' => ['endpoint' => 'https://s3-eu-central-2.ionoscloud.com', 'params' => ['Bucket' => 'my-bucket']]],
            'my-bucket/tmp/test.json',
            'https://s3-eu-central-2.ionoscloud.com/my-bucket/tmp/test.json',
        ];
        yield 'should construct URL from endpoint for MinIO' => [
            ['s3Options' => ['endpoint' => 'http://minio.local:9000', 'params' => ['Bucket' => 'test-bucket']]],
            'test-bucket/tmp/test.json',
            'http://minio.local:9000/test-bucket/tmp/test.json',
        ];
        yield 'should prefer baseUrl over endpoint' => [
            ['baseUrl' => 'https://cdn.example.com', 's3Options' => ['endpoint' => 'https://s3-eu-central-2.ionoscloud.com', 'params' => ['Bucket' => 'my-bucket']]],
            'bucket/tmp/test.json',
            'https://cdn.example.com/tmp/test.json',
        ];
        yield 'should handle endpoint without protocol' => [
            ['s3Options' => ['endpoint' => 's3.wasabisys.com', 'params' => ['Bucket' => 'my-bucket']]],
            'bucket/tmp/test.json',
            'https://s3.wasabisys.com/my-bucket/tmp/test.json',
        ];
        yield 'should use S3 Location when no endpoint configured (AWS)' => [
            ['s3Options' => ['region' => 'us-east-1', 'params' => ['Bucket' => 'my-bucket']]],
            'https://my-bucket.s3.us-east-1.amazonaws.com/tmp/test.json',
            'https://my-bucket.s3.us-east-1.amazonaws.com/tmp/test.json',
        ];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('compatibleProviders')]
    public function testS3CompatibleProviderUrlConstruction(array $options, string $location, string $expected): void
    {
        [$provider, $client] = $this->init($options);
        $client->uploadResults[] = ['Location' => $location, 'ETag' => '"abc"'];

        $file = self::createTestFile();
        $provider->upload($file);

        self::assertSame($expected, $file['url']);
    }

    // ---------------------------------------------------------------- configuration validation

    public function testShouldWarnWhenUsingStorageClassWithNonAwsEndpoint(): void
    {
        $this->init([
            's3Options' => ['endpoint' => 'https://minio.example.com', 'params' => ['Bucket' => 'test']],
            'providerConfig' => ['storageClass' => 'GLACIER'],
        ]);

        self::assertTrue($this->warned('Storage class'));
    }

    public function testShouldWarnWhenUsingKmsEncryptionWithNonAwsEndpoint(): void
    {
        $this->init([
            's3Options' => ['endpoint' => 'https://spaces.digitalocean.com', 'params' => ['Bucket' => 'test']],
            'providerConfig' => ['encryption' => ['type' => 'aws:kms', 'kmsKeyId' => 'test-key']],
        ]);

        self::assertTrue($this->warned('Encryption type'));
    }

    public function testShouldNotWarnWhenUsingAwsSpecificFeaturesWithAwsEndpoint(): void
    {
        $this->init([
            's3Options' => ['endpoint' => 'https://s3.us-east-1.amazonaws.com', 'params' => ['Bucket' => 'test']],
            'providerConfig' => ['storageClass' => 'GLACIER', 'encryption' => ['type' => 'aws:kms', 'kmsKeyId' => 'test-key']],
        ]);

        self::assertSame([], $this->warnings);
    }

    public function testShouldNotWarnWhenUsingAes256EncryptionWithNonAwsEndpoint(): void
    {
        $this->init([
            's3Options' => ['endpoint' => 'https://minio.example.com', 'params' => ['Bucket' => 'test']],
            'providerConfig' => ['encryption' => ['type' => 'AES256']],
        ]);

        self::assertFalse($this->warned('Encryption type'));
    }

    public function testShouldWarnWhenPartSizeIsBelowMinimum(): void
    {
        $this->init([
            's3Options' => ['params' => ['Bucket' => 'test']],
            'providerConfig' => ['multipart' => ['partSize' => 1024 * 1024]],
        ]);

        self::assertTrue($this->warned('below the minimum'));
    }

    public function testShouldWarnWhenQueueSizeIsTooHigh(): void
    {
        $this->init([
            's3Options' => ['params' => ['Bucket' => 'test']],
            'providerConfig' => ['multipart' => ['queueSize' => 32]],
        ]);

        self::assertTrue($this->warned('queueSize'));
    }

    public function testShouldNotWarnWithValidMultipartConfiguration(): void
    {
        $this->init([
            's3Options' => ['params' => ['Bucket' => 'test']],
            'providerConfig' => ['multipart' => ['partSize' => 10 * 1024 * 1024, 'queueSize' => 4]],
        ]);

        self::assertSame([], $this->warnings);
    }

    public function testRootLevelS3OptionsAreDeprecatedButMerged(): void
    {
        [$provider, $client] = $this->init(['params' => ['Bucket' => 'legacy', 'ACL' => 'private'], 'region' => 'eu-west-1']);

        self::assertTrue($this->warned("passed at root level of the plugin's providerOptions is deprecated"));
        self::assertTrue($provider->isPrivate());
        self::assertSame('eu-west-1', $client->config['region'] ?? null);
    }

    // ---------------------------------------------------------------- PHP-port: the other methods

    public function testGetSignedUrlPresignsObjectsFromTheBucketOnly(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'bucket', 'ACL' => 'private', 'signedUrlExpires' => 60]]]);

        $signed = $provider->getSignedUrl(['url' => 'https://bucket.s3.eu-west-1.amazonaws.com/tmp/test.json', 'path' => 'tmp', 'hash' => 'test', 'ext' => '.json'], ['Bucket' => 'other', 'ResponseContentDisposition' => 'attachment']);
        self::assertSame(['url' => 'signed:bucket/tmp/test.json?60'], $signed);
        self::assertEquals(['Bucket' => 'bucket', 'Key' => 'tmp/test.json', 'ResponseContentDisposition' => 'attachment'], $client->presignCalls[0][0]);

        // a URL from elsewhere is returned untouched
        self::assertSame(['url' => 'https://elsewhere.test/x.png'], $provider->getSignedUrl(['url' => 'https://elsewhere.test/x.png', 'hash' => 'x']));
    }

    public function testGetSignedUrlDefaultsTo15Minutes(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'bucket']]]);

        $provider->getSignedUrl(['url' => 'https://bucket.s3.eu-west-1.amazonaws.com/a.png', 'hash' => 'a', 'ext' => '.png']);

        self::assertSame(900, $client->presignCalls[0][1]);
    }

    public function testDeleteSendsDeleteObjectWithTheSecureBucketAndKey(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'bucket']]]);

        $provider->delete(self::createTestFile(), ['Bucket' => 'evil', 'Key' => 'evil', 'VersionId' => 'v1']);

        self::assertSame(['DeleteObject', ['VersionId' => 'v1', 'Bucket' => 'bucket', 'Key' => 'tmp/test.json']], $client->sendCalls[0]);
    }

    public function testReplaceDeletesTheOldObjectOnlyWhenTheKeyChanged(): void
    {
        [$provider, $client] = $this->init(['s3Options' => ['params' => ['Bucket' => 'bucket']]]);

        $provider->replace(self::createTestFile(), self::createTestFile());
        self::assertCount(1, $client->uploadCalls);
        self::assertSame([], $client->sendCalls);

        $provider->replaceStream(self::createTestFile(['hash' => 'new']), self::createTestFile(['hash' => 'old']));
        self::assertCount(2, $client->uploadCalls);
        self::assertSame(['DeleteObject', ['Bucket' => 'bucket', 'Key' => 'tmp/old.json']], $client->sendCalls[0]);
    }

    private function warned(string $needle): bool
    {
        foreach ($this->warnings as $warning) {
            if (str_contains($warning, $needle)) {
                return true;
            }
        }

        return false;
    }
}

/** The `vi.mock` of `S3Client` / `Upload`: records calls, returns scripted results. */
final class FakeS3Client extends S3Client
{
    /** @var list<array{params: array<string, mixed>, partSize?: int, queueSize?: int, leavePartsOnError?: bool}> */
    public array $uploadCalls = [];

    /** @var list<array<string, mixed>> */
    public array $uploadResults = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $sendCalls = [];

    /** @var list<array<string, mixed>|\Throwable> */
    public array $sendResults = [];

    /** @var list<array{0: array<string, mixed>, 1: int}> */
    public array $presignCalls = [];

    public function upload(array $options): array
    {
        $this->uploadCalls[] = $options;

        return array_shift($this->uploadResults) ?? ['Location' => 'https://validurl.test/tmp/test.json', 'ETag' => '"abc123def456"', '$metadata' => []];
    }

    public function send(string $command, array $input): array
    {
        $this->sendCalls[] = [$command, $input];
        $result = array_shift($this->sendResults) ?? [];
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }

    public function presignGetObject(array $input, int $expiresIn = 900): string
    {
        $this->presignCalls[] = [$input, $expiresIn];

        return "signed:{$input['Bucket']}/{$input['Key']}?{$expiresIn}";
    }
}
