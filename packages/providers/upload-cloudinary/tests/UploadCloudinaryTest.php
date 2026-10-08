<?php

declare(strict_types=1);

namespace Strapi\Provider\UploadCloudinary\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Provider\UploadCloudinary\CloudinaryClient;
use Strapi\Provider\UploadCloudinary\CloudinaryError;
use Strapi\Provider\UploadCloudinary\UploadCloudinary;
use Strapi\Utils\Errors\PayloadTooLargeError;

/**
 * PHP-port addition: upstream ships no tests for @strapi/provider-upload-cloudinary. These cover
 * src/index.ts's behaviour with the SDK replaced by a recording {@see CloudinaryClient}.
 */
final class UploadCloudinaryTest extends TestCase
{
    /**
     * @param array<string, mixed> $options
     * @return array{0: UploadCloudinary, 1: FakeCloudinaryClient}
     */
    private function init(array $options = ['cloud_name' => 'demo', 'api_key' => 'key', 'api_secret' => 'secret']): array
    {
        $client = null;
        $provider = UploadCloudinary::init($options, null, static function (array $config) use (&$client): CloudinaryClient {
            return $client = new FakeCloudinaryClient($config);
        });
        self::assertInstanceOf(FakeCloudinaryClient::class, $client);

        return [$provider, $client];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return \ArrayObject<string, mixed>
     */
    private static function file(array $overrides = []): \ArrayObject
    {
        return new \ArrayObject([
            'name' => 'photo.png', 'hash' => 'photo_abc', 'ext' => '.png', 'mime' => 'image/png',
            'size' => 12.5, 'url' => '', 'buffer' => 'png-bytes', ...$overrides,
        ]);
    }

    public function testInitPassesTheOptionsToCloudinaryConfig(): void
    {
        [, $client] = $this->init(['cloud_name' => 'demo', 'api_key' => 'k', 'api_secret' => 's', 'secure' => true]);

        self::assertSame('demo', $client->config['cloud_name']);
        self::assertSame('s', $client->config['api_secret']);
        self::assertTrue($client->config['secure']);
    }

    public function testUploadUsesUploadStreamBelow99MbWithUpstreamsOptions(): void
    {
        [$provider, $client] = $this->init();
        $file = self::file(['path' => 'folder/sub']);

        $provider->upload($file, ['tags' => ['strapi']]);

        self::assertSame('uploadStream', $client->calls[0][0]);
        self::assertSame('png-bytes', $client->calls[0][1]);
        self::assertSame([
            'resource_type' => 'auto',
            'public_id' => 'photo_abc',
            'filename' => 'photo_abc.png',
            'folder' => 'folder/sub',
            'tags' => ['strapi'],
        ], $client->calls[0][2]);

        self::assertSame('https://res.cloudinary.com/demo/image/upload/v1/folder/sub/photo_abc.png', $file['url']);
        self::assertSame(['public_id' => 'folder/sub/photo_abc', 'resource_type' => 'image'], $file['provider_metadata']);
        self::assertArrayNotHasKey('previewUrl', $file->getArrayCopy());
    }

    public function testUploadStreamPipesTheStream(): void
    {
        [$provider, $client] = $this->init();
        $stream = fopen('php://memory', 'r+b');
        self::assertNotFalse($stream);

        $provider->uploadStream(self::file(['buffer' => null, 'stream' => $stream, 'ext' => null]));

        self::assertSame($stream, $client->calls[0][1]);
        self::assertArrayNotHasKey('filename', $client->calls[0][2]);
        self::assertArrayNotHasKey('folder', $client->calls[0][2]);
    }

    public function testLargeOrUnsizedFilesUseTheChunkedUpload(): void
    {
        [$provider, $client] = $this->init();

        $provider->upload(self::file(['size' => 99000]));
        $provider->upload(self::file(['size' => 0]));
        $provider->upload(self::file(['size' => 98999.99]));

        self::assertSame(['uploadChunkedStream', 'uploadChunkedStream', 'uploadStream'], array_column($client->calls, 0));
    }

    public function testMissingStreamOrBuffer(): void
    {
        [$provider] = $this->init();

        $this->expectExceptionMessage('Missing file stream or buffer');

        $provider->upload(self::file(['buffer' => null]));
    }

    public function testVideosGetAGifPreviewUrl(): void
    {
        [$provider, $client] = $this->init();
        $client->uploadResult = ['public_id' => 'clips/movie', 'resource_type' => 'video', 'secure_url' => 'https://res.cloudinary.com/demo/video/upload/v1/clips/movie.mp4'];

        $file = self::file(['ext' => '.mp4']);
        $provider->upload($file);

        self::assertSame('https://res.cloudinary.com/demo/video/upload/c_scale,dl_200,vs_6,w_250/v1/clips/movie.gif', $file['previewUrl']);
        self::assertSame('https://res.cloudinary.com/demo/video/upload/v1/clips/movie.mp4', $file['url']);
        self::assertSame(['public_id' => 'clips/movie', 'resource_type' => 'video'], $file['provider_metadata']);
    }

    public function testFileSizeTooLargeBecomesAPayloadTooLargeError(): void
    {
        [$provider, $client] = $this->init();
        $client->uploadError = new CloudinaryError('File size too large. Got 120000000. Maximum is 104857600.', 400);

        $this->expectException(PayloadTooLargeError::class);

        $provider->upload(self::file());
    }

    public function testOtherUploadErrorsAreWrapped(): void
    {
        [$provider, $client] = $this->init();
        $client->uploadError = new CloudinaryError('Invalid Signature 123', 401);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Error uploading to cloudinary: Invalid Signature 123');

        $provider->upload(self::file());
    }

    public function testDeleteDestroysTheAssetWithItsResourceType(): void
    {
        [$provider, $client] = $this->init();

        $provider->delete(['provider_metadata' => ['public_id' => 'clips/movie', 'resource_type' => 'video']], ['type' => 'upload']);
        $provider->delete(['provider_metadata' => ['public_id' => 'photo']]);

        self::assertSame(['destroy', 'clips/movie', ['resource_type' => 'video', 'invalidate' => true, 'type' => 'upload']], $client->calls[0]);
        self::assertSame(['destroy', 'photo', ['resource_type' => 'image', 'invalidate' => true]], $client->calls[1]);
    }

    public function testDeleteAcceptsNotFoundAndWrapsOtherResults(): void
    {
        [$provider, $client] = $this->init();

        $client->destroyResult = ['result' => 'not found'];
        $provider->delete(['provider_metadata' => ['public_id' => 'gone']]);

        $client->destroyResult = ['result' => 'error'];
        $this->expectExceptionMessage('Error deleting on cloudinary: error');
        $provider->delete(['provider_metadata' => ['public_id' => 'x']]);
    }

    public function testDeleteWrapsClientErrors(): void
    {
        [$provider, $client] = $this->init();
        $client->destroyError = new CloudinaryError('Server returned unexpected status code - 502', 502);

        $this->expectExceptionMessage('Error deleting on cloudinary: Server returned unexpected status code - 502');

        $provider->delete(['provider_metadata' => ['public_id' => 'x']]);
    }

    public function testReplaceWithTheSamePublicIdOverwritesInPlace(): void
    {
        [$provider, $client] = $this->init();

        $provider->replace(self::file(['hash' => 'same']), ['provider_metadata' => ['public_id' => 'same', 'resource_type' => 'raw']], ['tags' => 'x']);

        self::assertCount(1, $client->calls);
        self::assertSame('uploadStream', $client->calls[0][0]);
        self::assertSame([
            'resource_type' => 'raw',
            'public_id' => 'same',
            'filename' => 'same.png',
            'overwrite' => true,
            'invalidate' => true,
            'tags' => 'x',
        ], $client->calls[0][2]);
    }

    public function testReplaceWithANewPublicIdUploadsThenDestroysTheOld(): void
    {
        [$provider, $client] = $this->init();

        $provider->replaceStream(self::file(['hash' => 'new']), new \ArrayObject(['provider_metadata' => ['public_id' => 'old']]));

        self::assertSame(['uploadStream', 'destroy'], array_column($client->calls, 0));
        self::assertSame(['destroy', 'old', ['resource_type' => 'image', 'invalidate' => true]], $client->calls[1]);

        // nothing to destroy without a previous public_id
        [$provider, $client] = $this->init();
        $provider->replace(self::file(), []);
        self::assertSame(['uploadStream'], array_column($client->calls, 0));
    }
}

/** Records the SDK calls; returns scripted results. */
final class FakeCloudinaryClient extends CloudinaryClient
{
    /** @var list<array{0: string, 1: mixed, 2: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, mixed>|null */
    public ?array $uploadResult = null;

    public ?\Throwable $uploadError = null;

    /** @var array<string, mixed> */
    public array $destroyResult = ['result' => 'ok'];

    public ?\Throwable $destroyError = null;

    public function uploadStream($body, array $options = []): array
    {
        $this->calls[] = ['uploadStream', $body, $options];

        return $this->result($options);
    }

    public function uploadChunkedStream($body, array $options = []): array
    {
        $this->calls[] = ['uploadChunkedStream', $body, $options];

        return $this->result($options);
    }

    public function destroy(string $publicId, array $options = []): array
    {
        $this->calls[] = ['destroy', $publicId, $options];
        if ($this->destroyError !== null) {
            throw $this->destroyError;
        }

        return $this->destroyResult;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function result(array $options): array
    {
        if ($this->uploadError !== null) {
            throw $this->uploadError;
        }
        if ($this->uploadResult !== null) {
            return $this->uploadResult;
        }

        $folder = is_string($options['folder'] ?? null) ? $options['folder'] . '/' : '';
        $publicId = $folder . (is_string($options['public_id'] ?? null) ? $options['public_id'] : '');

        return [
            'public_id' => $publicId,
            'resource_type' => 'image',
            'secure_url' => "https://res.cloudinary.com/demo/image/upload/v1/{$publicId}.png",
        ];
    }
}
