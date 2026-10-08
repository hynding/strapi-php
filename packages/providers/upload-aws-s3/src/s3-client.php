<?php

declare(strict_types=1);

namespace Strapi\Provider\UploadAwsS3;

/**
 * PHP-port addition: the slice of the AWS SDK for JavaScript v3 the provider uses —
 * `S3Client#send` for `PutObject`, `DeleteObject`, `HeadObject` and the multipart commands,
 * `Upload` from `@aws-sdk/lib-storage` (`upload()`), and `getSignedUrl` from
 * `@aws-sdk/s3-request-presigner` (`presignGetObject()`) — over core's `strapi.fetch`
 * (`Strapi\Core\Utils\Fetch`) and {@see SignatureV4}.
 *
 * Config is the `S3ClientConfig` shape: `region`, `endpoint`, `forcePathStyle`, `credentials`
 * (`{ accessKeyId, secretAccessKey, sessionToken? }` or a closure returning that, resolved on every
 * request), `requestChecksumCalculation` (`WHEN_SUPPORTED` default | `WHEN_REQUIRED`) and
 * `requestHandler.requestTimeout` (ms). Missing `region` / `credentials` fall back to the
 * environment (`AWS_REGION`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`).
 *
 * @phpstan-import-type Credentials from SignatureV4
 * @phpstan-type FetchResponse array{ok: bool, status: int, headers: array<string, string>, body: string}
 * @phpstan-type FetchFn \Closure(string, array{method?: string, headers?: array<string, string>, body?: string|null, timeout?: int|float}): FetchResponse
 */
class S3Client
{
    /** `@aws-sdk/lib-storage` MIN_PART_SIZE, also its default `partSize`. */
    public const int MIN_PART_SIZE = 5 * 1024 * 1024;

    public const int MAX_PARTS = 10000;

    /** Request headers set from `PutObjectCommandInput` (and `CreateMultipartUploadCommandInput`) members. */
    private const array PUT_HEADERS = [
        'ACL' => 'x-amz-acl',
        'CacheControl' => 'cache-control',
        'ContentDisposition' => 'content-disposition',
        'ContentEncoding' => 'content-encoding',
        'ContentLanguage' => 'content-language',
        'ContentType' => 'content-type',
        'ContentMD5' => 'content-md5',
        'Expires' => 'expires',
        'GrantFullControl' => 'x-amz-grant-full-control',
        'GrantRead' => 'x-amz-grant-read',
        'GrantReadACP' => 'x-amz-grant-read-acp',
        'GrantWriteACP' => 'x-amz-grant-write-acp',
        'ServerSideEncryption' => 'x-amz-server-side-encryption',
        'StorageClass' => 'x-amz-storage-class',
        'WebsiteRedirectLocation' => 'x-amz-website-redirect-location',
        'SSECustomerAlgorithm' => 'x-amz-server-side-encryption-customer-algorithm',
        'SSECustomerKey' => 'x-amz-server-side-encryption-customer-key',
        'SSECustomerKeyMD5' => 'x-amz-server-side-encryption-customer-key-md5',
        'SSEKMSKeyId' => 'x-amz-server-side-encryption-aws-kms-key-id',
        'SSEKMSEncryptionContext' => 'x-amz-server-side-encryption-context',
        'BucketKeyEnabled' => 'x-amz-server-side-encryption-bucket-key-enabled',
        'RequestPayer' => 'x-amz-request-payer',
        'Tagging' => 'x-amz-tagging',
        'ObjectLockMode' => 'x-amz-object-lock-mode',
        'ObjectLockRetainUntilDate' => 'x-amz-object-lock-retain-until-date',
        'ObjectLockLegalHoldStatus' => 'x-amz-object-lock-legal-hold',
        'ExpectedBucketOwner' => 'x-amz-expected-bucket-owner',
        'IfMatch' => 'if-match',
        'IfNoneMatch' => 'if-none-match',
    ];

    private const array DELETE_HEADERS = [
        'MFA' => 'x-amz-mfa',
        'RequestPayer' => 'x-amz-request-payer',
        'BypassGovernanceRetention' => 'x-amz-bypass-governance-retention',
        'ExpectedBucketOwner' => 'x-amz-expected-bucket-owner',
        'IfMatch' => 'if-match',
    ];

    private const array HEAD_HEADERS = [
        'IfMatch' => 'if-match',
        'IfNoneMatch' => 'if-none-match',
        'IfModifiedSince' => 'if-modified-since',
        'IfUnmodifiedSince' => 'if-unmodified-since',
        'Range' => 'range',
        'SSECustomerAlgorithm' => 'x-amz-server-side-encryption-customer-algorithm',
        'SSECustomerKey' => 'x-amz-server-side-encryption-customer-key',
        'SSECustomerKeyMD5' => 'x-amz-server-side-encryption-customer-key-md5',
        'RequestPayer' => 'x-amz-request-payer',
        'ExpectedBucketOwner' => 'x-amz-expected-bucket-owner',
    ];

    /** `GetObjectCommandInput` members a presigned URL carries in its query string. */
    private const array GET_QUERY = [
        'ResponseCacheControl' => 'response-cache-control',
        'ResponseContentDisposition' => 'response-content-disposition',
        'ResponseContentEncoding' => 'response-content-encoding',
        'ResponseContentLanguage' => 'response-content-language',
        'ResponseContentType' => 'response-content-type',
        'ResponseExpires' => 'response-expires',
        'VersionId' => 'versionId',
        'PartNumber' => 'partNumber',
    ];

    /** @var FetchFn|null */
    private readonly ?\Closure $fetch;

    private readonly \Closure $now;

    /**
     * @param array<string, mixed> $config
     * @param (\Closure(string, array<string, mixed>): array<string, mixed>)|callable|null $fetch `strapi.fetch`
     * @param (\Closure(): \DateTimeInterface)|null $now clock (tests)
     */
    public function __construct(public readonly array $config = [], ?callable $fetch = null, ?\Closure $now = null)
    {
        /** @var FetchFn|null $closure */
        $closure = $fetch !== null ? \Closure::fromCallable($fetch) : null;
        $this->fetch = $closure;
        $this->now = $now ?? static fn (): \DateTimeInterface => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * `s3Client.send(new <Command>Command(input))`.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function send(string $command, array $input): array
    {
        return match ($command) {
            'PutObject' => $this->putObject($input),
            'DeleteObject' => $this->deleteObject($input),
            'HeadObject' => $this->headObject($input),
            'CreateMultipartUpload' => $this->createMultipartUpload($input),
            'UploadPart' => $this->uploadPart($input),
            'CompleteMultipartUpload' => $this->completeMultipartUpload($input),
            'AbortMultipartUpload' => $this->abortMultipartUpload($input),
            default => throw new \InvalidArgumentException("Unsupported S3 command: {$command}"),
        };
    }

    /**
     * `new Upload({ client, params, partSize, queueSize, leavePartsOnError }).done()`: a single
     * `PutObject` when the body fits in one part, a multipart upload otherwise. Parts are read
     * from the stream and sent one at a time, so at most two parts are held in memory
     * (`queueSize` concurrency is not replicated).
     *
     * @param array{params: array<string, mixed>, partSize?: int, queueSize?: int, leavePartsOnError?: bool} $options
     * @return array<string, mixed> `{ Location, ETag, Bucket, Key, VersionId? }`
     */
    public function upload(array $options): array
    {
        $params = $options['params'];
        $partSize = $options['partSize'] ?? self::MIN_PART_SIZE;
        if ($partSize < self::MIN_PART_SIZE) {
            throw new \InvalidArgumentException('EntityTooSmall: Your proposed upload partsize [' . $partSize . '] is smaller than the minimum allowed size [' . self::MIN_PART_SIZE . '] (5MB)');
        }

        $body = $params['Body'] ?? null;
        if (!is_string($body) && !is_resource($body)) {
            throw new \InvalidArgumentException('Body Data is unsupported format, expected data to be one of: string | Uint8Array | Buffer | Readable | ReadableStream | Blob;.');
        }

        $read = is_string($body)
            ? (static function () use ($body, $partSize): \Generator {
                $length = strlen($body);
                for ($offset = 0; $offset < $length || $offset === 0; $offset += $partSize) {
                    yield substr($body, $offset, $partSize);
                }
            })()
            : self::chunks($body, $partSize);

        $first = (string) $read->current();
        $read->next();
        if (!$read->valid()) {
            // Single part: PutObject
            $result = $this->putObject([...$params, 'Body' => $first]);
            $result['$metadata'] ??= [];

            return [
                'Location' => $this->locationOf((string) $params['Bucket'], (string) $params['Key']),
                'ETag' => $result['ETag'] ?? null,
                'Bucket' => $params['Bucket'],
                'Key' => $params['Key'],
                ...(isset($result['VersionId']) ? ['VersionId' => $result['VersionId']] : []),
                '$metadata' => $result['$metadata'],
            ];
        }

        $createParams = $params;
        unset($createParams['Body'], $createParams['ContentMD5'], $createParams['IfMatch'], $createParams['IfNoneMatch']);
        $created = $this->createMultipartUpload($createParams);
        $uploadId = (string) $created['UploadId'];
        $algorithm = is_string($params['ChecksumAlgorithm'] ?? null) ? strtoupper($params['ChecksumAlgorithm']) : null;

        $parts = [];
        try {
            $partNumber = 1;
            $parts[] = $this->sendPart($params, $uploadId, $partNumber, (string) $first, $algorithm);
            while ($read->valid()) {
                if ($partNumber >= self::MAX_PARTS) {
                    throw new \RuntimeException('Exceeded ' . self::MAX_PARTS . ' parts in multipart upload to Bucket: ' . $params['Bucket'] . ' Key: ' . $params['Key'] . '.');
                }
                $parts[] = $this->sendPart($params, $uploadId, ++$partNumber, (string) $read->current(), $algorithm);
                $read->next();
            }

            return $this->completeMultipartUpload([
                'Bucket' => $params['Bucket'],
                'Key' => $params['Key'],
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => $parts],
                ...array_intersect_key($params, array_flip(['IfMatch', 'IfNoneMatch', 'ExpectedBucketOwner', 'RequestPayer'])),
            ]);
        } catch (\Throwable $error) {
            if (!($options['leavePartsOnError'] ?? false)) {
                try {
                    $this->abortMultipartUpload(['Bucket' => $params['Bucket'], 'Key' => $params['Key'], 'UploadId' => $uploadId]);
                } catch (\Throwable) {
                    // the original error is the one to report
                }
            }

            throw $error;
        }
    }

    /**
     * `getSignedUrl(s3Client, new GetObjectCommand(input), { expiresIn })`.
     *
     * @param array<string, mixed> $input
     */
    public function presignGetObject(array $input, int $expiresIn = 900): string
    {
        $query = ['X-Amz-Content-Sha256' => SignatureV4::UNSIGNED_PAYLOAD];
        foreach (self::GET_QUERY as $member => $name) {
            if (isset($input[$member]) && is_scalar($input[$member])) {
                $query[$name] = self::stringify($input[$member]);
            }
        }
        $query['x-id'] = 'GetObject';

        $url = $this->urlOf((string) $input['Bucket'], (string) $input['Key']) . '?' . SignatureV4::encodeQuery($query);

        return $this->signer()->presign('GET', $url, $this->credentials(), $expiresIn, ($this->now)());
    }

    /**
     * Object URL the request goes to: virtual-hosted style (`https://<bucket>.<host>/<key>`) unless
     * `forcePathStyle`, a bucket name that isn't DNS compatible (or holds dots, over https), or an
     * IP endpoint asks for path style (`https://<host>/<bucket>/<key>`).
     */
    public function urlOf(string $bucket, string $key): string
    {
        [$scheme, $host, $port, $basePath] = $this->endpoint();
        $encodedKey = implode('/', array_map(SignatureV4::uriEncode(...), explode('/', $key)));
        $authority = $port !== null ? "{$host}:{$port}" : $host;

        if ($this->pathStyle($bucket, $host, $scheme)) {
            return "{$scheme}://{$authority}{$basePath}/" . SignatureV4::uriEncode($bucket) . "/{$encodedKey}";
        }

        return "{$scheme}://{$bucket}.{$authority}{$basePath}/{$encodedKey}";
    }

    /** `Location` lib-storage reports for a single-part upload (the object URL). */
    public function locationOf(string $bucket, string $key): string
    {
        return $this->urlOf($bucket, $key);
    }

    public function region(): string
    {
        $region = $this->config['region'] ?? null;
        if (!is_string($region) || $region === '') {
            $env = getenv('AWS_REGION');
            $region = is_string($env) && $env !== '' ? $env : null;
        }
        if ($region === null) {
            throw new \RuntimeException('Region is missing');
        }

        return $region;
    }

    /** @return Credentials */
    public function credentials(): array
    {
        $credentials = $this->config['credentials'] ?? null;
        if ($credentials instanceof \Closure) {
            $credentials = $credentials();
        }

        if (!is_array($credentials)) {
            $accessKeyId = getenv('AWS_ACCESS_KEY_ID');
            $secretAccessKey = getenv('AWS_SECRET_ACCESS_KEY');
            if (!is_string($accessKeyId) || $accessKeyId === '' || !is_string($secretAccessKey) || $secretAccessKey === '') {
                throw new \RuntimeException('Could not load credentials from any providers');
            }
            $sessionToken = getenv('AWS_SESSION_TOKEN');
            $credentials = [
                'accessKeyId' => $accessKeyId,
                'secretAccessKey' => $secretAccessKey,
                ...(is_string($sessionToken) && $sessionToken !== '' ? ['sessionToken' => $sessionToken] : []),
            ];
        }

        $accessKeyId = $credentials['accessKeyId'] ?? null;
        $secretAccessKey = $credentials['secretAccessKey'] ?? null;
        if (!is_string($accessKeyId) || !is_string($secretAccessKey)) {
            throw new \RuntimeException('Could not load credentials from any providers');
        }
        $sessionToken = $credentials['sessionToken'] ?? null;

        return [
            'accessKeyId' => $accessKeyId,
            'secretAccessKey' => $secretAccessKey,
            ...(is_string($sessionToken) && $sessionToken !== '' ? ['sessionToken' => $sessionToken] : []),
        ];
    }

    // ---------------------------------------------------------------- commands

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function putObject(array $input): array
    {
        $body = $input['Body'] ?? '';
        if (is_resource($body)) {
            $body = (string) stream_get_contents($body);
        }
        $body = is_string($body) ? $body : '';

        $headers = $this->headersFrom($input, self::PUT_HEADERS);
        $algorithm = is_string($input['ChecksumAlgorithm'] ?? null) ? strtoupper($input['ChecksumAlgorithm']) : null;
        if ($algorithm === null && $this->checksumWhenSupported() && !isset($input['ContentMD5'])) {
            $algorithm = 'CRC32';
        }
        if ($algorithm !== null) {
            $headers['x-amz-sdk-checksum-algorithm'] = $algorithm;
            $headers[Checksum::header($algorithm)] = Checksum::compute($algorithm, $body);
        }

        $response = $this->request('PUT', $this->urlOf((string) $input['Bucket'], (string) $input['Key']), $headers, $body);

        return self::withMeta($response, [
            'ETag' => $response['headers']['etag'] ?? null,
            'VersionId' => $response['headers']['x-amz-version-id'] ?? null,
            'ServerSideEncryption' => $response['headers']['x-amz-server-side-encryption'] ?? null,
            ...($algorithm !== null ? [Checksum::element($algorithm) => $response['headers'][Checksum::header($algorithm)] ?? null] : []),
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function deleteObject(array $input): array
    {
        $url = $this->urlOf((string) $input['Bucket'], (string) $input['Key']);
        if (isset($input['VersionId']) && is_scalar($input['VersionId'])) {
            $url .= '?versionId=' . SignatureV4::uriEncode(self::stringify($input['VersionId']));
        }

        $response = $this->request('DELETE', $url, $this->headersFrom($input, self::DELETE_HEADERS));

        $deleteMarker = $response['headers']['x-amz-delete-marker'] ?? null;

        return self::withMeta($response, [
            'DeleteMarker' => $deleteMarker !== null ? $deleteMarker === 'true' : null,
            'VersionId' => $response['headers']['x-amz-version-id'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function headObject(array $input): array
    {
        $query = [];
        if (isset($input['VersionId']) && is_scalar($input['VersionId'])) {
            $query['versionId'] = self::stringify($input['VersionId']);
        }
        if (isset($input['PartNumber']) && is_scalar($input['PartNumber'])) {
            $query['partNumber'] = self::stringify($input['PartNumber']);
        }
        $url = $this->urlOf((string) $input['Bucket'], (string) $input['Key']) . ($query !== [] ? '?' . SignatureV4::encodeQuery($query) : '');

        $response = $this->request('HEAD', $url, $this->headersFrom($input, self::HEAD_HEADERS));
        $h = $response['headers'];

        $metadata = [];
        foreach ($h as $name => $value) {
            if (str_starts_with($name, 'x-amz-meta-')) {
                $metadata[substr($name, 11)] = $value;
            }
        }
        $lastModified = isset($h['last-modified']) ? \DateTimeImmutable::createFromFormat(\DATE_RFC7231, $h['last-modified']) : false;

        return self::withMeta($response, [
            'ETag' => $h['etag'] ?? null,
            'ContentLength' => isset($h['content-length']) ? (int) $h['content-length'] : null,
            'ContentType' => $h['content-type'] ?? null,
            'LastModified' => $lastModified !== false ? $lastModified : null,
            'StorageClass' => $h['x-amz-storage-class'] ?? null,
            'ServerSideEncryption' => $h['x-amz-server-side-encryption'] ?? null,
            'VersionId' => $h['x-amz-version-id'] ?? null,
            'Metadata' => $metadata,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function createMultipartUpload(array $input): array
    {
        $headers = $this->headersFrom($input, self::PUT_HEADERS);
        if (is_string($input['ChecksumAlgorithm'] ?? null)) {
            $algorithm = strtoupper($input['ChecksumAlgorithm']);
            $headers['x-amz-checksum-algorithm'] = $algorithm;
            if ($algorithm === 'CRC64NVME') {
                $headers['x-amz-checksum-type'] = 'FULL_OBJECT';
            }
        }

        $response = $this->request('POST', $this->urlOf((string) $input['Bucket'], (string) $input['Key']) . '?uploads', $headers, '');
        $xml = self::xml($response['body']);

        return self::withMeta($response, [
            'Bucket' => (string) ($xml->Bucket ?? $input['Bucket']),
            'Key' => (string) ($xml->Key ?? $input['Key']),
            'UploadId' => (string) ($xml->UploadId ?? ''),
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function uploadPart(array $input): array
    {
        $body = is_string($input['Body'] ?? null) ? $input['Body'] : '';
        $headers = [];
        $algorithm = is_string($input['ChecksumAlgorithm'] ?? null) ? strtoupper($input['ChecksumAlgorithm']) : null;
        if ($algorithm !== null) {
            $headers['x-amz-sdk-checksum-algorithm'] = $algorithm;
            $headers[Checksum::header($algorithm)] = Checksum::compute($algorithm, $body);
        }
        foreach (['SSECustomerAlgorithm', 'SSECustomerKey', 'SSECustomerKeyMD5', 'RequestPayer', 'ExpectedBucketOwner'] as $member) {
            if (isset($input[$member])) {
                $headers += $this->headersFrom([$member => $input[$member]], self::PUT_HEADERS);
            }
        }

        $query = SignatureV4::encodeQuery(['partNumber' => (string) $input['PartNumber'], 'uploadId' => (string) $input['UploadId']]);
        $response = $this->request('PUT', $this->urlOf((string) $input['Bucket'], (string) $input['Key']) . '?' . $query, $headers, $body);

        $result = ['ETag' => $response['headers']['etag'] ?? null];
        if ($algorithm !== null) {
            $result[Checksum::element($algorithm)] = $response['headers'][Checksum::header($algorithm)] ?? $headers[Checksum::header($algorithm)];
        }

        return self::withMeta($response, $result);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function completeMultipartUpload(array $input): array
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUpload xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
        $parts = $input['MultipartUpload']['Parts'] ?? [];
        foreach (is_array($parts) ? $parts : [] as $part) {
            $xml .= '<Part>';
            foreach ($part as $name => $value) {
                if ($value !== null && is_scalar($value)) {
                    $xml .= "<{$name}>" . htmlspecialchars(self::stringify($value), ENT_XML1) . "</{$name}>";
                }
            }
            $xml .= '</Part>';
        }
        $xml .= '</CompleteMultipartUpload>';

        $headers = $this->headersFrom($input, ['IfMatch' => 'if-match', 'IfNoneMatch' => 'if-none-match', 'ExpectedBucketOwner' => 'x-amz-expected-bucket-owner', 'RequestPayer' => 'x-amz-request-payer']);
        $headers['content-type'] = 'application/xml';

        $url = $this->urlOf((string) $input['Bucket'], (string) $input['Key']) . '?uploadId=' . SignatureV4::uriEncode((string) $input['UploadId']);
        $response = $this->request('POST', $url, $headers, $xml);

        // CompleteMultipartUpload can fail with a 200 and an <Error> body
        $result = self::xml($response['body']);
        if ($result->getName() === 'Error') {
            throw self::exception($response);
        }

        return self::withMeta($response, [
            'Location' => isset($result->Location) ? (string) $result->Location : null,
            'Bucket' => isset($result->Bucket) ? (string) $result->Bucket : $input['Bucket'],
            'Key' => isset($result->Key) ? (string) $result->Key : $input['Key'],
            'ETag' => isset($result->ETag) ? (string) $result->ETag : null,
            'VersionId' => $response['headers']['x-amz-version-id'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function abortMultipartUpload(array $input): array
    {
        $url = $this->urlOf((string) $input['Bucket'], (string) $input['Key']) . '?uploadId=' . SignatureV4::uriEncode((string) $input['UploadId']);

        return self::withMeta($this->request('DELETE', $url, []), []);
    }

    // ---------------------------------------------------------------- transport

    /**
     * Signs and sends a request; throws {@see S3ServiceException} on a non-2xx response.
     *
     * @param array<string, string> $headers lower-case names
     * @return FetchResponse
     */
    private function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        if ($this->fetch === null) {
            throw new \RuntimeException('Upload AWS S3 provider: no HTTP client (the provider needs `strapi.fetch`; pass the Strapi instance to init())');
        }

        $payload = $body ?? '';
        $headers['host'] = SignatureV4::hostOf($url);
        $headers['x-amz-content-sha256'] = hash('sha256', $payload);
        $signed = $this->signer()->sign($method, $url, $headers, $headers['x-amz-content-sha256'], $this->credentials(), ($this->now)());

        if ($body !== null) {
            // not signed: PHP's http wrapper would otherwise omit it for an empty body
            $signed['content-length'] = (string) strlen($body);
            $signed['content-type'] ??= 'application/octet-stream';
        }

        $timeout = $this->config['requestHandler']['requestTimeout'] ?? null;
        $response = ($this->fetch)($url, [
            'method' => $method,
            'headers' => $signed,
            'body' => $body,
            'timeout' => is_numeric($timeout) && $timeout > 0 ? $timeout / 1000 : 300,
        ]);
        $response['headers'] = array_change_key_case($response['headers'], CASE_LOWER);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw self::exception($response);
        }

        return $response;
    }

    /** @param FetchResponse $response */
    private static function exception(array $response): S3ServiceException
    {
        $status = $response['status'];
        $code = null;
        $message = null;
        $details = [];
        if ($response['body'] !== '') {
            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($response['body']);
            libxml_use_internal_errors($previous);
            if ($xml !== false) {
                foreach ($xml->children() as $child) {
                    $details[$child->getName()] = (string) $child;
                }
                $code = $details['Code'] ?? null;
                $message = $details['Message'] ?? null;
            }
        }

        // body-less errors (HEAD) are named after the status, as the SDK does
        $code ??= match ($status) {
            301 => 'PermanentRedirect',
            304 => 'NotModified',
            400 => 'BadRequest',
            403 => 'Forbidden',
            404 => 'NotFound',
            412 => 'PreconditionFailed',
            default => 'UnknownError',
        };

        return new S3ServiceException($code, $message ?? $code, $status, $details);
    }

    private static function xml(string $body): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new \RuntimeException('Unable to parse S3 response: ' . substr($body, 0, 200));
        }

        return $xml;
    }

    /**
     * @param FetchResponse $response
     * @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    private static function withMeta(array $response, array $output): array
    {
        $meta = ['httpStatusCode' => $response['status']];
        if (isset($response['headers']['x-amz-request-id'])) {
            $meta['requestId'] = $response['headers']['x-amz-request-id'];
        }

        return [...array_filter($output, static fn (mixed $v): bool => $v !== null), '$metadata' => $meta];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $map member => header
     * @return array<string, string>
     */
    private function headersFrom(array $input, array $map): array
    {
        $headers = [];
        foreach ($map as $member => $header) {
            $value = $input[$member] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ($member === 'SSECustomerKey' && is_string($value)) {
                // middleware-ssec: base64 the raw key and add its MD5
                $headers[$header] = base64_encode($value);
                $headers['x-amz-server-side-encryption-customer-key-md5'] ??= base64_encode(md5($value, true));
                continue;
            }
            if ($value instanceof \DateTimeInterface) {
                $value = $member === 'ObjectLockRetainUntilDate'
                    ? \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')
                    : \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_RFC7231);
            }
            if (is_scalar($value)) {
                $headers[$header] = self::stringify($value);
            }
        }

        $metadata = $input['Metadata'] ?? null;
        if (is_array($metadata) && array_key_exists('Metadata', $input) && isset($map['ContentType'])) {
            foreach ($metadata as $key => $value) {
                if (is_scalar($value)) {
                    $headers['x-amz-meta-' . strtolower((string) $key)] = self::stringify($value);
                }
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed> the `Parts` entry
     */
    private function sendPart(array $params, string $uploadId, int $partNumber, string $chunk, ?string $algorithm): array
    {
        $result = $this->uploadPart([
            'Bucket' => $params['Bucket'],
            'Key' => $params['Key'],
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
            'Body' => $chunk,
            ...($algorithm !== null ? ['ChecksumAlgorithm' => $algorithm] : []),
            ...array_intersect_key($params, array_flip(['SSECustomerAlgorithm', 'SSECustomerKey', 'SSECustomerKeyMD5', 'RequestPayer', 'ExpectedBucketOwner'])),
        ]);

        return [
            'ETag' => $result['ETag'] ?? null,
            'PartNumber' => $partNumber,
            ...($algorithm !== null ? [Checksum::element($algorithm) => $result[Checksum::element($algorithm)] ?? null] : []),
        ];
    }

    /**
     * Reads a stream in `$size`-byte chunks (always yields at least one, possibly empty, chunk).
     *
     * @param resource $stream
     * @return \Generator<int, string>
     */
    private static function chunks($stream, int $size): \Generator
    {
        $yielded = false;
        while (true) {
            $chunk = '';
            while (strlen($chunk) < $size && !feof($stream)) {
                $read = fread($stream, max(1, $size - strlen($chunk)));
                if ($read === false) {
                    throw new \RuntimeException('Unable to read the upload stream');
                }
                if ($read === '' && feof($stream)) {
                    break;
                }
                $chunk .= $read;
            }
            if ($chunk === '' && $yielded) {
                return;
            }
            $yielded = true;
            yield $chunk;
            if (strlen($chunk) < $size) {
                return;
            }
        }
    }

    private function checksumWhenSupported(): bool
    {
        $setting = $this->config['requestChecksumCalculation'] ?? getenv('AWS_REQUEST_CHECKSUM_CALCULATION');

        return !is_string($setting) || $setting === '' || strtoupper($setting) !== 'WHEN_REQUIRED';
    }

    private function signer(): SignatureV4
    {
        return new SignatureV4('s3', $this->region());
    }

    /** @return array{0: string, 1: string, 2: int|null, 3: string} scheme, host, port, base path */
    private function endpoint(): array
    {
        $endpoint = $this->config['endpoint'] ?? null;
        if (is_string($endpoint) && $endpoint !== '') {
            $url = preg_match('~^[a-z][a-z0-9+.-]*://~i', $endpoint) === 1 ? $endpoint : "https://{$endpoint}";
            $parts = parse_url($url);
            if ($parts === false || !isset($parts['host'])) {
                throw new \InvalidArgumentException("Invalid endpoint: {$endpoint}");
            }

            return [
                strtolower($parts['scheme'] ?? 'https'),
                $parts['host'],
                $parts['port'] ?? null,
                rtrim($parts['path'] ?? '', '/'),
            ];
        }

        $region = $this->region();
        $suffix = str_starts_with($region, 'cn-') ? 'amazonaws.com.cn' : 'amazonaws.com';

        return ['https', "s3.{$region}.{$suffix}", null, ''];
    }

    private function pathStyle(string $bucket, string $host, string $scheme): bool
    {
        if (($this->config['forcePathStyle'] ?? false) === true) {
            return true;
        }
        $dnsCompatible = preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket) === 1
            && !str_contains($bucket, '..')
            && filter_var($bucket, FILTER_VALIDATE_IP) === false;
        if (!$dnsCompatible || ($scheme === 'https' && str_contains($bucket, '.'))) {
            return true;
        }

        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || $host === 'localhost';
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            $value === true => 'true',
            $value === false => 'false',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
