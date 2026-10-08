<?php

declare(strict_types=1);

namespace Strapi\Provider\UploadAwsS3;

use Strapi\Types\Core\Strapi;

/**
 * Port of packages/providers/upload-aws-s3/src/index.ts.
 *
 * `init(providerOptions)` returns the provider instance (the object of methods upstream returns).
 * The options keep upstream's shape — `{ baseUrl?, rootPath?, s3Options: S3ClientConfig & { params:
 * { Bucket, ACL?, signedUrlExpires? } }, providerConfig? }` — so `config/plugins.ts` ports 1:1. The
 * AWS SDK is replaced by {@see S3Client} over `strapi.fetch`; the upload plugin passes the Strapi
 * instance as second argument. A third argument (PHP-port addition) builds the client from the
 * resolved config, which is how the tests replace the SDK as upstream's `vi.mock` does.
 *
 * Files are `\ArrayAccess`es with `hash`, `ext`, `path`, `mime` and a `stream` (a stream resource)
 * or a `buffer` (a binary string); `url` (and `etag`) are set on the file once uploaded.
 *
 * @phpstan-type ProviderConfig array{checksumAlgorithm?: string, preventOverwrite?: bool, storageClass?: string, encryption?: array{type: string, kmsKeyId?: string}, tags?: array<string, string>, multipart?: array{partSize?: int, queueSize?: int, leavePartsOnError?: bool}}
 */
final class UploadAwsS3
{
    public const array STORAGE_CLASSES = ['STANDARD', 'REDUCED_REDUNDANCY', 'STANDARD_IA', 'ONEZONE_IA', 'INTELLIGENT_TIERING', 'GLACIER', 'DEEP_ARCHIVE', 'GLACIER_IR'];

    public const array ENCRYPTION_TYPES = ['AES256', 'aws:kms', 'aws:kms:dsse'];

    private readonly string $filePrefix;

    /**
     * @param array<string, mixed> $config resolved `S3ClientConfig` (with `params`)
     * @param array<string, mixed>|null $providerConfig
     */
    private function __construct(
        private readonly array $config,
        private readonly S3Client $s3Client,
        private readonly ?string $baseUrl,
        ?string $rootPath,
        private readonly ?array $providerConfig,
    ) {
        $this->filePrefix = $rootPath !== null && $rootPath !== '' ? rtrim($rootPath, '/') . '/' : '';
    }

    /**
     * @param array<string, mixed> $options
     * @param (\Closure(array<string, mixed>, (callable|null)): S3Client)|null $createClient PHP-port addition: `new S3Client(config)`
     */
    public static function init(array $options = [], ?Strapi $strapi = null, ?\Closure $createClient = null): self
    {
        $baseUrl = is_string($options['baseUrl'] ?? null) && $options['baseUrl'] !== '' ? $options['baseUrl'] : null;
        $rootPath = is_string($options['rootPath'] ?? null) ? $options['rootPath'] : null;
        $s3Options = is_array($options['s3Options'] ?? null) ? $options['s3Options'] : null;
        $providerConfig = is_array($options['providerConfig'] ?? null) ? $options['providerConfig'] : null;
        $legacyS3Options = array_diff_key($options, array_flip(['baseUrl', 'rootPath', 's3Options', 'providerConfig']));

        // Validate configuration and emit warnings for potential issues
        self::validateProviderConfig($providerConfig, $s3Options);

        // TODO V5 change config structure to avoid having to do this
        $config = self::getConfig($s3Options ?? [], $legacyS3Options);

        $fetch = $strapi !== null && $strapi->has('fetch') ? $strapi->get('fetch') : null;
        $fetch = is_callable($fetch) ? $fetch : null;
        $s3Client = $createClient !== null ? $createClient($config, $fetch) : new S3Client($config, $fetch);

        return new self($config, $s3Client, $baseUrl, $rootPath, $providerConfig);
    }

    /**
     * @param array<string, mixed> $s3Options
     * @param array<string, mixed> $legacyS3Options
     * @return array<string, mixed>
     */
    private static function getConfig(array $s3Options, array $legacyS3Options): array
    {
        if ($legacyS3Options !== []) {
            Utils::emitWarning("S3 configuration options passed at root level of the plugin's providerOptions is deprecated and will be removed in a future release. Please wrap them inside the 's3Options:{}' property.");
        }

        $credentials = Utils::extractCredentials(['s3Options' => $s3Options, ...$legacyS3Options]);
        $config = [
            ...$s3Options,
            ...$legacyS3Options,
            ...($credentials !== null ? ['credentials' => $credentials] : []),
        ];

        if (is_array($config['params'] ?? null)) {
            // Only set default ACL when ACL is not explicitly present in params.
            // Since April 2023, new AWS S3 buckets have ACLs disabled by default
            // ("Bucket owner enforced"). Sending an ACL header to such buckets
            // throws AccessControlListNotSupported. To disable ACLs, set `'ACL' => null`
            // (upstream: an `ACL` key holding `undefined`); a missing key means public-read.
            if (!array_key_exists('ACL', $config['params'])) {
                $config['params']['ACL'] = 'public-read';
            }
        } else {
            throw new \RuntimeException('Upload AWS S3 provider: `params` are required in the config object');
        }

        return $config;
    }

    /**
     * @param array<string, mixed>|null $providerConfig
     * @param array<string, mixed>|null $s3Options
     */
    private static function validateProviderConfig(?array $providerConfig, ?array $s3Options): void
    {
        if ($providerConfig === null) {
            return;
        }

        $endpoint = $s3Options['endpoint'] ?? '';
        $isNonAws = self::isNonAwsEndpoint(is_string($endpoint) ? $endpoint : '');

        // Warn about AWS-specific features when using non-AWS endpoints
        if ($isNonAws) {
            if (!empty($providerConfig['storageClass']) && is_string($providerConfig['storageClass'])) {
                Utils::emitWarning("Storage class '{$providerConfig['storageClass']}' is AWS S3-specific and may be ignored by your S3-compatible provider.");
            }

            $type = $providerConfig['encryption']['type'] ?? null;
            if (is_string($type) && $type !== '' && $type !== 'AES256') {
                Utils::emitWarning("Encryption type '{$type}' is AWS S3-specific. Consider using 'AES256' for better compatibility.");
            }
        }

        // Validate multipart configuration
        $partSize = $providerConfig['multipart']['partSize'] ?? null;
        if (is_int($partSize) && $partSize > 0) {
            $minPartSize = 5 * 1024 * 1024; // 5MB
            $maxPartSize = 5 * 1024 * 1024 * 1024; // 5GB

            if ($partSize < $minPartSize) {
                Utils::emitWarning("Multipart partSize {$partSize} is below the minimum of 5MB. This may cause upload failures.");
            }
            if ($partSize > $maxPartSize) {
                Utils::emitWarning("Multipart partSize {$partSize} exceeds the maximum of 5GB. This may cause upload failures.");
            }
        }

        $queueSize = $providerConfig['multipart']['queueSize'] ?? null;
        if (is_int($queueSize) && $queueSize > 16) {
            Utils::emitWarning("Multipart queueSize {$queueSize} is high and may cause memory issues. Consider using 4-8.");
        }
    }

    /** Checks if the endpoint appears to be a non-AWS S3-compatible provider. */
    private static function isNonAwsEndpoint(string $endpoint): bool
    {
        if ($endpoint === '') {
            return false;
        }

        return preg_match('/\.amazonaws\.com$/i', $endpoint) !== 1 && preg_match('/\.amazonaws\.com\.cn$/i', $endpoint) !== 1;
    }

    /**
     * Sanitizes a path component to prevent path traversal attacks.
     * Removes directory traversal sequences and normalizes the path.
     */
    private static function sanitizePathComponent(mixed $component): string
    {
        if (!is_scalar($component) || (string) $component === '') {
            return '';
        }

        $component = str_replace('..', '', (string) $component);
        $component = (string) preg_replace('~^/+|/+$~', '', $component);

        return (string) preg_replace('~/+~', '/', $component);
    }

    /** Converts a tags array to the S3 Tagging header format: key1=value1&key2=value2. */
    private static function formatTagsForHeader(mixed $tags): ?string
    {
        if (!is_array($tags) || $tags === []) {
            return null;
        }

        $pairs = [];
        foreach ($tags as $key => $value) {
            $pairs[] = self::encodeURIComponent((string) $key) . '=' . self::encodeURIComponent(is_scalar($value) ? (string) $value : '');
        }

        return implode('&', $pairs);
    }

    /** JS `encodeURIComponent` (keeps `!'()*`, unlike `rawurlencode`). */
    private static function encodeURIComponent(string $value): string
    {
        return strtr(rawurlencode($value), ['%21' => '!', '%27' => "'", '%28' => '(', '%29' => ')', '%2A' => '*']);
    }

    /** @param \ArrayAccess<string, mixed>|array<string, mixed> $file */
    private function getFileKey(\ArrayAccess|array $file): string
    {
        $sanitizedPath = self::sanitizePathComponent($file['path'] ?? null);
        $path = $sanitizedPath !== '' ? "{$sanitizedPath}/" : '';
        $sanitizedHash = self::sanitizePathComponent($file['hash'] ?? null);
        $ext = $file['ext'] ?? null;
        $sanitizedExt = is_string($ext) && $ext !== '' ? (string) preg_replace('/[^a-zA-Z0-9.]/', '', $ext) : '';

        return "{$this->filePrefix}{$path}{$sanitizedHash}{$sanitizedExt}";
    }

    /** @return array{Bucket: string, ACL?: string|null, signedUrlExpires?: int} */
    private function params(): array
    {
        /** @var array{Bucket: string, ACL?: string|null, signedUrlExpires?: int} $params */
        $params = $this->config['params'];

        return $params;
    }

    /**
     * Builds the upload parameters including all configured features.
     *
     * @param \ArrayAccess<string, mixed> $file
     * @param array<string, mixed> $customParams
     * @return array<string, mixed>
     */
    private function buildUploadParams(\ArrayAccess $file, string $fileKey, array $customParams = []): array
    {
        $stream = $file['stream'] ?? null;
        $buffer = $file['buffer'] ?? null;
        if (!is_resource($stream) && !is_string($buffer)) {
            throw new \RuntimeException('Missing file stream or buffer');
        }

        $acl = $this->params()['ACL'] ?? null;
        $params = [
            'Bucket' => $this->params()['Bucket'],
            'Key' => $fileKey,
            'Body' => is_resource($stream) ? $stream : $buffer,
            // ACL is optional to support providers like Cloudflare R2 that don't support ACLs.
            // Set params.ACL to null to disable ACL headers.
            ...($acl !== null && $acl !== '' ? ['ACL' => $acl] : []),
            'ContentType' => $file['mime'] ?? null,
        ];

        $providerConfig = $this->providerConfig ?? [];

        // Checksum validation
        $checksumAlgorithm = $providerConfig['checksumAlgorithm'] ?? null;
        if (is_string($checksumAlgorithm) && in_array($checksumAlgorithm, Checksum::ALGORITHMS, true)) {
            $params['ChecksumAlgorithm'] = $checksumAlgorithm;
        }

        // Conditional writes - prevent overwrite
        if (!empty($providerConfig['preventOverwrite'])) {
            $params['IfNoneMatch'] = '*';
        }

        // Storage class
        $storageClass = $providerConfig['storageClass'] ?? null;
        if (is_string($storageClass) && in_array($storageClass, self::STORAGE_CLASSES, true)) {
            $params['StorageClass'] = $storageClass;
        }

        // Server-side encryption
        $encryption = $providerConfig['encryption'] ?? null;
        if (is_array($encryption)) {
            $type = $encryption['type'] ?? null;
            if (is_string($type) && in_array($type, self::ENCRYPTION_TYPES, true)) {
                $params['ServerSideEncryption'] = $type;
                if (!empty($encryption['kmsKeyId'])) {
                    $params['SSEKMSKeyId'] = $encryption['kmsKeyId'];
                }
            }
        }

        // Object tagging
        $tagging = self::formatTagsForHeader($providerConfig['tags'] ?? null);
        if ($tagging !== null) {
            $params['Tagging'] = $tagging;
        }

        // Merge customParams but preserve critical security parameters
        // Bucket, Key, and Body must not be overridden by customParams
        unset($customParams['Bucket'], $customParams['Key'], $customParams['Body']);

        return [...$params, ...$customParams];
    }

    /**
     * Constructs the correct file URL.
     * Handles S3-compatible providers that return incorrect Location formats.
     *
     * Some providers (IONOS, some MinIO configs) return malformed Location
     * values like "bucket/key" without protocol or domain. For these, we
     * fall back to constructing the URL from the endpoint config.
     *
     * Other providers (AWS, Scaleway, DigitalOcean, Backblaze) return
     * correct Location URLs that should be trusted as-is, since they
     * already use the correct URL style (virtual-hosted or path-style).
     */
    private function constructFileUrl(string $fileKey, string $uploadLocation): string
    {
        // Priority 1: Use baseUrl if configured (CDN or custom domain)
        if ($this->baseUrl !== null) {
            return rtrim($this->baseUrl, '/') . "/{$fileKey}";
        }

        // Priority 2: Use the Location from S3 response if it's a valid URL.
        if ($uploadLocation !== '' && preg_match('~^https?://~', $uploadLocation) === 1) {
            return $uploadLocation;
        }

        // Priority 3: Construct URL from endpoint if configured.
        $endpoint = $this->config['endpoint'] ?? null;
        if (is_string($endpoint) && $endpoint !== '') {
            $endpointUrl = str_starts_with($endpoint, 'http') ? $endpoint : "https://{$endpoint}";

            return rtrim($endpointUrl, '/') . "/{$this->params()['Bucket']}/{$fileKey}";
        }

        // Priority 4: Prepend https if Location exists but lacks protocol
        if ($uploadLocation !== '') {
            return "https://{$uploadLocation}";
        }

        // Priority 5: Construct from AWS default pattern
        return "https://{$this->params()['Bucket']}.s3.amazonaws.com/{$fileKey}";
    }

    /**
     * @param \ArrayAccess<string, mixed> $file
     * @param array<string, mixed>|null $customParams
     */
    private function doUpload(\ArrayAccess $file, ?array $customParams = null): void
    {
        $fileKey = $this->getFileKey($file);
        $params = $this->buildUploadParams($file, $fileKey, $customParams ?? []);

        $uploadOptions = ['params' => $params];

        // Multipart configuration
        $multipart = $this->providerConfig['multipart'] ?? null;
        if (is_array($multipart)) {
            if (!empty($multipart['partSize'])) {
                $uploadOptions['partSize'] = (int) $multipart['partSize'];
            }
            if (!empty($multipart['queueSize'])) {
                $uploadOptions['queueSize'] = (int) $multipart['queueSize'];
            }
            if (array_key_exists('leavePartsOnError', $multipart) && $multipart['leavePartsOnError'] !== null) {
                $uploadOptions['leavePartsOnError'] = (bool) $multipart['leavePartsOnError'];
            }
        }

        $this->finishUpload($file, $fileKey, $this->s3Client->upload($uploadOptions));
    }

    /**
     * @param \ArrayAccess<string, mixed> $file
     * @param array<string, mixed> $result
     */
    private function finishUpload(\ArrayAccess $file, string $fileKey, array $result): void
    {
        $location = $result['Location'] ?? '';
        $file['url'] = $this->constructFileUrl($fileKey, is_string($location) ? $location : '');
        if (is_string($result['ETag'] ?? null) && $result['ETag'] !== '') {
            $file['etag'] = str_replace('"', '', $result['ETag']);
        }
    }

    /** Returns whether the bucket is configured with private ACL. */
    public function isPrivate(): bool
    {
        return ($this->params()['ACL'] ?? null) === 'private';
    }

    /**
     * Returns the current provider configuration.
     *
     * @return array<string, mixed>|null
     */
    public function getProviderConfig(): ?array
    {
        return $this->providerConfig;
    }

    /**
     * Generates a signed URL for accessing a private object.
     *
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     * @param array<string, mixed>|null $customParams
     * @return array{url: string}
     */
    public function getSignedUrl(\ArrayAccess|array $file, ?array $customParams = null): array
    {
        $url = is_string($file['url'] ?? null) ? $file['url'] : '';
        if (!Utils::isUrlFromBucket($url, $this->params()['Bucket'], $this->baseUrl ?? '')) {
            return ['url' => $url];
        }

        $fileKey = $this->getFileKey($file);
        $expires = $this->params()['signedUrlExpires'] ?? null;

        // customParams first, then the secure values (no Bucket / Key override)
        $signed = $this->s3Client->presignGetObject(
            [...($customParams ?? []), 'Bucket' => $this->params()['Bucket'], 'Key' => $fileKey],
            is_numeric($expires) ? (int) $expires : 15 * 60,
        );

        return ['url' => $signed];
    }

    /**
     * Uploads a file using streaming.
     *
     * @param \ArrayAccess<string, mixed> $file
     * @param array<string, mixed>|null $customParams
     */
    public function uploadStream(\ArrayAccess $file, ?array $customParams = null): void
    {
        $this->doUpload($file, $customParams);
    }

    /**
     * Uploads a file to S3.
     *
     * @param \ArrayAccess<string, mixed> $file
     * @param array<string, mixed>|null $customParams
     */
    public function upload(\ArrayAccess $file, ?array $customParams = null): void
    {
        $this->doUpload($file, $customParams);
    }

    /**
     * Replaces an existing object in S3. Since `PutObject` with the same key
     * overwrites the existing object, this is just an upload of the new file
     * — and if the key changed, we delete the old object afterwards so we
     * never leave the bucket in a state where the asset is missing.
     *
     * @param \ArrayAccess<string, mixed> $newFile
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $oldFile
     * @param array<string, mixed>|null $customParams
     */
    public function replaceStream(\ArrayAccess $newFile, \ArrayAccess|array $oldFile, ?array $customParams = null): void
    {
        $this->doReplace($newFile, $oldFile, $customParams ?? []);
    }

    /**
     * @param \ArrayAccess<string, mixed> $newFile
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $oldFile
     * @param array<string, mixed>|null $customParams
     */
    public function replace(\ArrayAccess $newFile, \ArrayAccess|array $oldFile, ?array $customParams = null): void
    {
        $this->doReplace($newFile, $oldFile, $customParams ?? []);
    }

    /**
     * @param \ArrayAccess<string, mixed> $newFile
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $oldFile
     * @param array<string, mixed> $customParams
     */
    private function doReplace(\ArrayAccess $newFile, \ArrayAccess|array $oldFile, array $customParams): void
    {
        $newKey = $this->getFileKey($newFile);
        $oldKey = $this->getFileKey($oldFile);

        $this->doUpload($newFile, $customParams);

        if ($newKey !== $oldKey) {
            unset($customParams['Bucket'], $customParams['Key']);
            $this->s3Client->send('DeleteObject', [...$customParams, 'Bucket' => $this->params()['Bucket'], 'Key' => $oldKey]);
        }
    }

    /**
     * Uploads a file only if the existing object matches the expected ETag (optimistic locking).
     * Throws a PreconditionFailed error if the ETag does not match.
     *
     * @param \ArrayAccess<string, mixed> $file
     * @param array<string, mixed> $customParams
     */
    public function uploadIfMatch(\ArrayAccess $file, string $expectedETag, array $customParams = []): void
    {
        $fileKey = $this->getFileKey($file);
        $params = $this->buildUploadParams($file, $fileKey, [...$customParams, 'IfMatch' => $expectedETag]);

        $this->finishUpload($file, $fileKey, $this->s3Client->upload(['params' => $params]));
    }

    /**
     * Retrieves object metadata including its ETag.
     *
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     * @return array{etag: string|null, contentLength: mixed, contentType: mixed, lastModified: mixed, storageClass: mixed, serverSideEncryption: mixed}
     */
    public function getObjectMetadata(\ArrayAccess|array $file): array
    {
        $response = $this->s3Client->send('HeadObject', [
            'Bucket' => $this->params()['Bucket'],
            'Key' => $this->getFileKey($file),
        ]);

        return [
            'etag' => is_string($response['ETag'] ?? null) ? str_replace('"', '', $response['ETag']) : null,
            'contentLength' => $response['ContentLength'] ?? null,
            'contentType' => $response['ContentType'] ?? null,
            'lastModified' => $response['LastModified'] ?? null,
            'storageClass' => $response['StorageClass'] ?? null,
            'serverSideEncryption' => $response['ServerSideEncryption'] ?? null,
        ];
    }

    /**
     * Checks if an object exists in the bucket.
     *
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     */
    public function objectExists(\ArrayAccess|array $file): bool
    {
        try {
            $this->getObjectMetadata($file);

            return true;
        } catch (\Throwable $error) {
            $name = property_exists($error, 'name') ? $error->name : null;
            $status = $error instanceof S3ServiceException ? $error->metadata['httpStatusCode'] : null;
            if ($name === 'NotFound' || $status === 404) {
                return false;
            }

            throw $error;
        }
    }

    /**
     * Deletes an object from S3.
     *
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     * @param array<string, mixed>|null $customParams
     * @return array<string, mixed> the `DeleteObjectCommandOutput`
     */
    public function delete(\ArrayAccess|array $file, ?array $customParams = null): array
    {
        // customParams first, then the secure values
        $safeParams = $customParams ?? [];
        unset($safeParams['Bucket'], $safeParams['Key']);

        return $this->s3Client->send('DeleteObject', [
            ...$safeParams,
            'Bucket' => $this->params()['Bucket'],
            'Key' => $this->getFileKey($file),
        ]);
    }
}
