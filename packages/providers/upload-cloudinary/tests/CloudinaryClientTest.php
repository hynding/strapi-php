<?php

declare(strict_types=1);

namespace Strapi\Provider\UploadCloudinary\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Provider\UploadCloudinary\CloudinaryClient;
use Strapi\Provider\UploadCloudinary\CloudinaryError;
use Strapi\Provider\UploadCloudinary\UploadCloudinary;

/**
 * PHP-port addition: the Cloudinary client (the SDK's part) at the HTTP level, with
 * `strapi.fetch` replaced by a recorder — no network.
 */
final class CloudinaryClientTest extends TestCase
{
    private const array CONFIG = ['cloud_name' => 'demo', 'api_key' => '1234', 'api_secret' => 'abcd'];

    /** @var list<array{url: string, method: string, headers: array<string, string>, body: string}> */
    private array $requests = [];

    /** @var list<array{status: int, body: string}> */
    private array $responses = [];

    private string|false $cloudinaryUrl = false;

    protected function setUp(): void
    {
        $this->requests = [];
        $this->responses = [];
        $this->cloudinaryUrl = getenv('CLOUDINARY_URL');
        putenv('CLOUDINARY_URL');
    }

    protected function tearDown(): void
    {
        putenv($this->cloudinaryUrl === false ? 'CLOUDINARY_URL' : "CLOUDINARY_URL={$this->cloudinaryUrl}");
    }

    /** @param array<string, mixed> $config */
    private function client(array $config = self::CONFIG): CloudinaryClient
    {
        return new CloudinaryClient($config, function (string $url, array $options): array {
            /** @var array<string, string> $headers */
            $headers = $options['headers'] ?? [];
            $this->requests[] = ['url' => $url, 'method' => (string) ($options['method'] ?? 'GET'), 'headers' => $headers, 'body' => (string) ($options['body'] ?? '')];
            $response = array_shift($this->responses) ?? ['status' => 200, 'body' => '{"result":"ok"}'];

            return ['ok' => $response['status'] < 300, 'status' => $response['status'], 'headers' => [], 'body' => $response['body']];
        }, static fn (): int => 1315060510, static fn (): string => 'BOUNDARY');
    }

    /**
     * The multipart fields of a recorded request.
     *
     * @return array<string, string>
     */
    private static function fields(string $body): array
    {
        $fields = [];
        foreach (explode("--BOUNDARY\r\n", $body) as $part) {
            if (preg_match('~^Content-Disposition: form-data; name="([^"]+)"(?:; filename="([^"]*)")?\r\n(?:Content-Type: [^\r]+\r\n)?\r\n(.*)\r\n(?:--BOUNDARY--)?$~s', $part, $m) === 1) {
                $fields[$m[1]] = $m[2] !== '' ? "{$m[2]}:{$m[3]}" : $m[3];
            }
        }

        return $fields;
    }

    /** Cloudinary's documented example (Authentication signatures, "Manual signature generation"). */
    public function testDocumentedSignatureExample(): void
    {
        $params = ['eager' => 'w_400,h_300,c_pad|w_260,h_200,c_crop', 'public_id' => 'sample_image', 'timestamp' => 1315060510];

        self::assertSame('eager=w_400,h_300,c_pad|w_260,h_200,c_crop&public_id=sample_image&timestamp=1315060510', CloudinaryClient::apiStringToSign($params));
        self::assertSame('bfd09f95f331f558cbd1320e67aa8d488770583e', CloudinaryClient::apiSignRequest($params, 'abcd'));
        // signature_algorithm: 'sha256' hashes the same string
        self::assertSame(hash('sha256', 'eager=w_400,h_300,c_pad|w_260,h_200,c_crop&public_id=sample_image&timestamp=1315060510abcd'), CloudinaryClient::apiSignRequest($params, 'abcd', 'sha256'));
    }

    public function testStringToSignDropsBlanksSortsAndEscapesAmpersandsInVersion2(): void
    {
        $params = ['timestamp' => 1, 'folder' => '', 'notification_url' => null, 'context' => 'a=b&c', 'tags' => ['x', 'y']];

        self::assertSame('context=a=b%26c&tags=x,y&timestamp=1', CloudinaryClient::apiStringToSign($params));
        self::assertSame('context=a=b&c&tags=x,y&timestamp=1', CloudinaryClient::apiStringToSign($params, 1));
    }

    public function testUploadStreamPostsASignedMultipartRequest(): void
    {
        $this->responses[] = ['status' => 200, 'body' => '{"public_id":"folder/photo","resource_type":"image","secure_url":"https://res.cloudinary.com/demo/image/upload/v1/folder/photo.png"}'];

        $result = $this->client()->uploadStream('PNGDATA', [
            'resource_type' => 'auto', 'public_id' => 'photo', 'filename' => 'photo.png', 'folder' => 'folder',
            'overwrite' => true, 'invalidate' => false, 'tags' => ['a', 'b'], 'context' => ['alt' => 'x=y|z'],
        ]);

        self::assertSame('folder/photo', $result['public_id']);
        $request = $this->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.cloudinary.com/v1_1/demo/auto/upload', $request['url']);
        self::assertSame('multipart/form-data; boundary=BOUNDARY', $request['headers']['Content-Type']);
        self::assertSame((string) strlen($request['body']), $request['headers']['Content-Length']);
        self::assertStringEndsWith("\r\n--BOUNDARY--", $request['body']);

        $fields = self::fields($request['body']);
        $signed = [
            'timestamp' => '1315060510', 'folder' => 'folder', 'public_id' => 'photo', 'invalidate' => '0',
            'overwrite' => '1', 'tags' => 'a,b', 'context' => 'alt=x\=y\|z',
        ];
        foreach ($signed as $key => $value) {
            self::assertSame($value, $fields[$key] ?? null, $key);
        }
        self::assertSame('1234', $fields['api_key']);
        self::assertSame('photo.png:PNGDATA', $fields['file']);
        self::assertArrayNotHasKey('resource_type', $fields);
        self::assertArrayNotHasKey('filename', $fields);
        self::assertArrayNotHasKey('api_secret', $fields);
        self::assertSame(CloudinaryClient::apiSignRequest($signed, 'abcd'), $fields['signature']);
    }

    public function testUploadStreamReadsAStreamAndSha256Signatures(): void
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertNotFalse($stream);
        fwrite($stream, 'streamed');
        rewind($stream);

        $this->client([...self::CONFIG, 'signature_algorithm' => 'sha256'])->uploadStream($stream, ['public_id' => 'p']);

        $fields = self::fields($this->requests[0]['body']);
        self::assertSame('https://api.cloudinary.com/v1_1/demo/image/upload', $this->requests[0]['url']);
        self::assertSame('file:streamed', $fields['file']);
        self::assertSame(hash('sha256', 'public_id=p&timestamp=1315060510abcd'), $fields['signature']);
    }

    public function testChunkedUploadSendsContentRangesWithOneUploadId(): void
    {
        $this->responses = [
            ['status' => 200, 'body' => '{"done":false}'],
            ['status' => 200, 'body' => '{"done":false}'],
            ['status' => 200, 'body' => '{"public_id":"big","resource_type":"video","secure_url":"https://x"}'],
        ];

        $result = $this->client()->uploadChunkedStream('0123456789', ['resource_type' => 'auto', 'public_id' => 'big', 'chunk_size' => 4]);

        self::assertSame('big', $result['public_id']);
        self::assertSame(['bytes 0-3/-1', 'bytes 4-7/-1', 'bytes 8-9/10'], array_map(static fn (array $r): string => $r['headers']['Content-Range'], $this->requests));
        self::assertSame(['BOUNDARY'], array_values(array_unique(array_map(static fn (array $r): string => $r['headers']['X-Unique-Upload-Id'], $this->requests))));
        self::assertSame(['file:0123', 'file:4567', 'file:89'], array_map(static fn (array $r): string => self::fields($r['body'])['file'], $this->requests));
        self::assertSame('https://api.cloudinary.com/v1_1/demo/auto/upload', $this->requests[2]['url']);
    }

    public function testChunkedUploadOfAStreamWithTheDefaultChunkSize(): void
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertNotFalse($stream);
        fwrite($stream, 'small');
        rewind($stream);

        $this->client()->uploadChunkedStream($stream, ['public_id' => 'p']);

        self::assertCount(1, $this->requests);
        self::assertSame('bytes 0-4/5', $this->requests[0]['headers']['Content-Range']);
    }

    public function testApiErrorsAreThrown(): void
    {
        $this->responses[] = ['status' => 400, 'body' => '{"error":{"message":"File size too large. Got 200. Maximum is 100."}}'];

        try {
            $this->client()->uploadStream('x', ['public_id' => 'p']);
            self::fail('expected an error');
        } catch (CloudinaryError $error) {
            self::assertSame('File size too large. Got 200. Maximum is 100.', $error->getMessage());
            self::assertSame(400, $error->httpCode);
        }

        $this->responses[] = ['status' => 502, 'body' => 'Bad Gateway'];
        $this->expectExceptionMessage('Server returned unexpected status code - 502');
        $this->client()->uploadStream('x', ['public_id' => 'p']);
    }

    public function testAChunkErrorStopsTheUpload(): void
    {
        $this->responses[] = ['status' => 400, 'body' => '{"error":{"message":"Invalid Signature"}}'];

        try {
            $this->client()->uploadChunkedStream('0123456789', ['chunk_size' => 4]);
            self::fail('expected an error');
        } catch (CloudinaryError $error) {
            self::assertSame('Invalid Signature', $error->getMessage());
        }
        self::assertCount(1, $this->requests);
    }

    public function testDestroy(): void
    {
        $this->responses[] = ['status' => 200, 'body' => '{"result":"ok"}'];

        $result = $this->client()->destroy('folder/photo', ['resource_type' => 'video', 'invalidate' => true]);

        self::assertSame(['result' => 'ok'], $result);
        self::assertSame('https://api.cloudinary.com/v1_1/demo/video/destroy', $this->requests[0]['url']);
        $fields = self::fields($this->requests[0]['body']);
        self::assertSame(['timestamp' => '1315060510', 'invalidate' => '1', 'public_id' => 'folder/photo'], array_intersect_key($fields, array_flip(['timestamp', 'invalidate', 'public_id'])));
        self::assertArrayNotHasKey('file', $fields);
        self::assertSame(CloudinaryClient::apiSignRequest(['timestamp' => 1315060510, 'invalidate' => 1, 'public_id' => 'folder/photo'], 'abcd'), $fields['signature']);
        self::assertStringEndsWith("\r\n--BOUNDARY--", $this->requests[0]['body']);
    }

    public function testMissingCredentials(): void
    {
        $this->expectExceptionMessage('Must supply api_secret');

        $this->client(['cloud_name' => 'demo', 'api_key' => 'k'])->destroy('x');
    }

    public function testConfigFromCloudinaryUrl(): void
    {
        putenv('CLOUDINARY_URL=cloudinary://111:sec%2Fret@envcloud');

        $client = $this->client(['api_key' => 'override']);
        self::assertSame('envcloud', $client->config['cloud_name']);
        self::assertSame('sec/ret', $client->config['api_secret']);
        self::assertSame('override', $client->config['api_key']);

        $client->destroy('x');
        self::assertSame('https://api.cloudinary.com/v1_1/envcloud/image/destroy', $this->requests[0]['url']);
    }

    public function testUrl(): void
    {
        $client = $this->client();

        self::assertSame(
            'https://res.cloudinary.com/demo/video/upload/c_scale,dl_200,vs_6,w_250/v1/clips/movie.gif',
            $client->url('clips/movie.gif', ['video_sampling' => 6, 'delay' => 200, 'width' => 250, 'crop' => 'scale', 'resource_type' => 'video']),
        );
        self::assertSame('https://res.cloudinary.com/demo/image/upload/sample.jpg', $client->url('sample.jpg'));
        self::assertSame('http://res.cloudinary.com/demo/image/upload/a%20b.jpg', $client->url('a b.jpg', ['secure' => false]));
        self::assertSame('https://demo-res.cloudinary.com/image/upload/sample.jpg', $client->url('sample.jpg', ['private_cdn' => true]));
    }

    public function testTheProviderUsesStrapiFetchEndToEnd(): void
    {
        $strapi = $this->createMock(\Strapi\Types\Core\Strapi::class);
        $strapi->method('has')->willReturnCallback(static fn (string $name): bool => $name === 'fetch');
        $strapi->method('get')->willReturnCallback(fn (string $name): mixed => $name === 'fetch' ? function (string $url, array $options): array {
            $this->requests[] = ['url' => $url, 'method' => 'POST', 'headers' => [], 'body' => (string) ($options['body'] ?? '')];

            return ['ok' => true, 'status' => 200, 'headers' => [], 'body' => '{"public_id":"abc","resource_type":"image","secure_url":"https://res.cloudinary.com/demo/image/upload/v1/abc.png"}'];
        } : null);

        $provider = UploadCloudinary::init(self::CONFIG, $strapi);
        $file = new \ArrayObject(['hash' => 'abc', 'ext' => '.png', 'size' => 1.5, 'buffer' => 'png']);
        $provider->upload($file);

        self::assertSame('https://api.cloudinary.com/v1_1/demo/auto/upload', $this->requests[0]['url']);
        self::assertSame('https://res.cloudinary.com/demo/image/upload/v1/abc.png', $file['url']);
        self::assertSame(['public_id' => 'abc', 'resource_type' => 'image'], $file['provider_metadata']);
    }
}
