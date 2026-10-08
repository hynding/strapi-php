<?php

declare(strict_types=1);

namespace Strapi\Provider\UploadCloudinary;

use Strapi\Types\Core\Strapi;
use Strapi\Utils\Errors\PayloadTooLargeError;

/**
 * Port of packages/providers/upload-cloudinary/src/index.ts.
 *
 * `init(providerOptions)` returns the provider instance (the object of methods upstream returns).
 * The options are what upstream passes to `cloudinary.config()` (`cloud_name`, `api_key`,
 * `api_secret`, …), so `config/plugins.ts` ports 1:1. The SDK is replaced by
 * {@see CloudinaryClient} over `strapi.fetch`; the upload plugin passes the Strapi instance as
 * second argument. A third argument (PHP-port addition) builds the client from the options, which
 * is how the tests replace the SDK.
 *
 * Files are `\ArrayAccess`es with `hash`, `ext`, `path`, `size` (KB) and a `stream` (a stream
 * resource) or a `buffer` (a binary string); `url`, `previewUrl` (videos) and
 * `provider_metadata` (`{ public_id, resource_type }`) are set on the file once uploaded.
 */
final class UploadCloudinary
{
    private function __construct(private readonly CloudinaryClient $cloudinary)
    {
    }

    /**
     * @param array<string, mixed> $options
     * @param (\Closure(array<string, mixed>, (callable|null)): CloudinaryClient)|null $createClient PHP-port addition
     */
    public static function init(array $options = [], ?Strapi $strapi = null, ?\Closure $createClient = null): self
    {
        $fetch = $strapi !== null && $strapi->has('fetch') ? $strapi->get('fetch') : null;
        $fetch = is_callable($fetch) ? $fetch : null;

        // cloudinary.config(options)
        $cloudinary = $createClient !== null ? $createClient($options, $fetch) : new CloudinaryClient($options, $fetch);

        return new self($cloudinary);
    }

    /**
     * @param \ArrayAccess<string, mixed> $file
     * @param array<string, mixed> $customConfig
     */
    private function doUpload(\ArrayAccess $file, array $customConfig = []): void
    {
        $hash = is_scalar($file['hash'] ?? null) ? (string) $file['hash'] : '';
        $config = [
            'resource_type' => 'auto',
            'public_id' => $hash,
        ];

        $ext = $file['ext'] ?? null;
        if (is_string($ext) && $ext !== '') {
            $config['filename'] = "{$hash}{$ext}";
        }

        $path = $file['path'] ?? null;
        if (is_string($path) && $path !== '') {
            $config['folder'] = $path;
        }

        $stream = $file['stream'] ?? null;
        $buffer = $file['buffer'] ?? null;
        if (!is_resource($stream) && !is_string($buffer)) {
            throw new \RuntimeException('Missing file stream or buffer');
        }
        $body = is_resource($stream) ? $stream : (string) $buffer;

        // For files smaller than 99 MB use regular upload as it tends to be faster
        // and fallback to chunked upload for larger files as that's required by Cloudinary.
        // https://support.cloudinary.com/hc/en-us/community/posts/360009586100-Upload-movie-video-with-large-size?page=1#community_comment_360002140099
        // The Cloudinary's max limit for regular upload is actually 100 MB but add some headroom
        // for size counting shenanigans. (Strapi provides the size in kilobytes rounded to two decimal places here).
        $size = $file['size'] ?? null;
        $regular = is_numeric($size) && $size > 0 && $size < 1000 * 99;

        try {
            $image = $regular
                ? $this->cloudinary->uploadStream($body, [...$config, ...$customConfig])
                : $this->cloudinary->uploadChunkedStream($body, [...$config, ...$customConfig]);
        } catch (CloudinaryError $err) {
            if (str_contains($err->getMessage(), 'File size too large')) {
                throw new PayloadTooLargeError();
            }

            throw new \RuntimeException("Error uploading to cloudinary: {$err->getMessage()}", 0, $err);
        }

        $publicId = is_scalar($image['public_id'] ?? null) ? (string) $image['public_id'] : '';

        if (($image['resource_type'] ?? null) === 'video') {
            $file['previewUrl'] = $this->cloudinary->url("{$publicId}.gif", [
                'video_sampling' => 6,
                'delay' => 200,
                'width' => 250,
                'crop' => 'scale',
                'resource_type' => 'video',
            ]);
        }

        $file['url'] = $image['secure_url'] ?? null;
        $file['provider_metadata'] = [
            'public_id' => $image['public_id'] ?? null,
            'resource_type' => $image['resource_type'] ?? null,
        ];
    }

    /**
     * @param \ArrayAccess<string, mixed> $newFile
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $oldFile
     * @param array<string, mixed> $customConfig
     */
    private function doReplace(\ArrayAccess $newFile, \ArrayAccess|array $oldFile, array $customConfig = []): void
    {
        $metadata = $oldFile['provider_metadata'] ?? null;
        $metadata = is_array($metadata) ? $metadata : [];
        $oldPublicId = $metadata['public_id'] ?? null;
        $oldResourceType = $metadata['resource_type'] ?? null;

        // If the public_id is preserved, we can overwrite in place and invalidate
        // the CDN cache in a single call.
        if (!empty($oldPublicId) && ($newFile['hash'] ?? null) === $oldPublicId) {
            $this->doUpload($newFile, [
                'public_id' => $oldPublicId,
                'overwrite' => true,
                'invalidate' => true,
                ...(!empty($oldResourceType) ? ['resource_type' => $oldResourceType] : []),
                ...$customConfig,
            ]);

            return;
        }

        // The public_id differs — upload the new file first, then destroy the old.
        $this->doUpload($newFile, $customConfig);

        if (!empty($oldPublicId) && is_scalar($oldPublicId)) {
            try {
                $this->cloudinary->destroy((string) $oldPublicId, [
                    'resource_type' => is_string($oldResourceType) && $oldResourceType !== '' ? $oldResourceType : 'image',
                    'invalidate' => true,
                ]);
            } catch (\Throwable $error) {
                throw new \RuntimeException("Error deleting on cloudinary: {$error->getMessage()}", 0, $error);
            }
        }
    }

    /**
     * @param \ArrayAccess<string, mixed> $file
     * @param array<string, mixed>|null $customConfig
     */
    public function uploadStream(\ArrayAccess $file, ?array $customConfig = null): void
    {
        $this->doUpload($file, $customConfig ?? []);
    }

    /**
     * @param \ArrayAccess<string, mixed> $file
     * @param array<string, mixed>|null $customConfig
     */
    public function upload(\ArrayAccess $file, ?array $customConfig = null): void
    {
        $this->doUpload($file, $customConfig ?? []);
    }

    /**
     * @param \ArrayAccess<string, mixed> $newFile
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $oldFile
     * @param array<string, mixed>|null $customConfig
     */
    public function replaceStream(\ArrayAccess $newFile, \ArrayAccess|array $oldFile, ?array $customConfig = null): void
    {
        $this->doReplace($newFile, $oldFile, $customConfig ?? []);
    }

    /**
     * @param \ArrayAccess<string, mixed> $newFile
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $oldFile
     * @param array<string, mixed>|null $customConfig
     */
    public function replace(\ArrayAccess $newFile, \ArrayAccess|array $oldFile, ?array $customConfig = null): void
    {
        $this->doReplace($newFile, $oldFile, $customConfig ?? []);
    }

    /**
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     * @param array<string, mixed>|null $customConfig
     */
    public function delete(\ArrayAccess|array $file, ?array $customConfig = null): void
    {
        try {
            $metadata = $file['provider_metadata'] ?? null;
            $metadata = is_array($metadata) ? $metadata : [];
            $resourceType = $metadata['resource_type'] ?? null;
            $publicId = $metadata['public_id'] ?? null;

            $deleteConfig = [
                'resource_type' => is_string($resourceType) && $resourceType !== '' ? $resourceType : 'image',
                'invalidate' => true,
                ...($customConfig ?? []),
            ];

            // `${publicId}` upstream: a missing public_id is sent as "undefined"
            $response = $this->cloudinary->destroy(is_scalar($publicId) ? (string) $publicId : 'undefined', $deleteConfig);

            $result = $response['result'] ?? null;
            if ($result !== 'ok' && $result !== 'not found') {
                throw new \RuntimeException(is_scalar($result) ? (string) $result : 'undefined');
            }
        } catch (\Throwable $error) {
            throw new \RuntimeException("Error deleting on cloudinary: {$error->getMessage()}", 0, $error);
        }
    }
}
