# strapi/provider-upload-aws-s3

Amazon S3 upload provider (port of @strapi/provider-upload-aws-s3)

| | |
| --- | --- |
| Upstream | [`@strapi/provider-upload-aws-s3`](https://github.com/strapi/strapi/tree/develop/packages/providers/upload-aws-s3) |
| Namespace | `Strapi\Provider\UploadAwsS3\\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules.

## Port status

| Upstream | PHP |
| --- | --- |
| `src/index.ts` | `src/index.php` — `UploadAwsS3::init(array $providerOptions, ?Strapi $strapi)` returns the provider instance: `isPrivate`, `getProviderConfig`, `getSignedUrl`, `uploadStream`, `upload`, `replaceStream`, `replace`, `uploadIfMatch`, `getObjectMetadata`, `objectExists`, `delete` |
| `src/utils.ts` | `src/utils.php` — `Utils::isUrlFromBucket`, `Utils::extractCredentials` (+ `Utils::emitWarning`, the `process.emitWarning` / `console.warn` sink) |
| `@aws-sdk/client-s3`, `@aws-sdk/lib-storage`, `@aws-sdk/s3-request-presigner` | `src/s3-client.php` (`S3Client`), `src/signature-v4.php` (`SignatureV4`), `src/checksum.php` (`Checksum`), `src/s3-service-exception.php` (`S3ServiceException`) — PHP-port additions, no Composer dependency |
| `src/__tests__/upload-aws-s3.vitest.test.ts` | `tests/UploadAwsS3Test.php` |
| `src/__tests__/utils.vitest.test.ts` | `tests/UtilsTest.php` |
| `src/__tests__/is-url-from-bucket.vitest.test.ts` | `tests/IsUrlFromBucketTest.php` |
| — | `tests/SignatureV4Test.php` (AWS's published SigV4 examples), `tests/S3ClientTest.php` (HTTP level, no network) |

## Configuration

Option names are upstream's, so `config/plugins.ts` ports key for key:

```php
// config/plugins.php
use Strapi\Utils\EnvHelper;

return static fn (EnvHelper $env): array => [
    'upload' => [
        'config' => [
            'provider' => 'aws-s3',
            'providerOptions' => [
                'baseUrl' => $env('CDN_URL'),
                'rootPath' => $env('CDN_ROOT_PATH'),
                's3Options' => [
                    'credentials' => [
                        'accessKeyId' => $env('AWS_ACCESS_KEY_ID'),
                        'secretAccessKey' => $env('AWS_ACCESS_SECRET'),
                    ],
                    'region' => $env('AWS_REGION'),
                    // S3-compatible services: 'endpoint' => 'http://localhost:9000', 'forcePathStyle' => true,
                    'params' => [
                        'ACL' => $env('AWS_ACL', 'public-read'), // null: send no ACL (R2, "bucket owner enforced" buckets)
                        'signedUrlExpires' => $env->int('AWS_SIGNED_URL_EXPIRES', 15 * 60),
                        'Bucket' => $env('AWS_BUCKET'),
                    ],
                ],
                'providerConfig' => [
                    'checksumAlgorithm' => 'CRC64NVME',
                    'preventOverwrite' => true,
                    'storageClass' => 'INTELLIGENT_TIERING',
                    'encryption' => ['type' => 'AES256'],
                    'tags' => ['project' => 'website'],
                    'multipart' => ['partSize' => 10 * 1024 * 1024, 'queueSize' => 4, 'leavePartsOnError' => false],
                ],
            ],
            'actionOptions' => ['upload' => [], 'uploadStream' => [], 'delete' => []],
        ],
    ],
];
```

`actionOptions` / custom params are `PutObject` / `DeleteObject` / `GetObject` input members
(`ContentDisposition`, `CacheControl`, `Metadata`, `VersionId`, `ResponseContentDisposition`…);
`Bucket`, `Key` and `Body` can't be overridden. `credentials` may also be a closure returning
`['accessKeyId' => …, 'secretAccessKey' => …, 'sessionToken' => …]`, called on every request
(upstream's credential provider function).

## The S3 client

The AWS SDK is replaced by a small client over core's `strapi.fetch` (`Strapi\Core\Utils\Fetch`,
which honours `server.proxy`):

- **Signing**: AWS Signature Version 4, header-signed requests with the payload's SHA-256, and
  query-string presigned `GET` URLs (`X-Amz-Content-Sha256=UNSIGNED-PAYLOAD`, `x-id=GetObject`,
  as `@aws-sdk/s3-request-presigner` produces). `signedUrlExpires` above a week is rejected as the
  SDK does. Verified against AWS's published examples.
- **Uploads** (`@aws-sdk/lib-storage` `Upload`): a body that fits in one part (`partSize`, default
  and minimum 5 MB) is a single `PutObject`; a larger one is a multipart upload
  (`CreateMultipartUpload`, `UploadPart`…, `CompleteMultipartUpload`, aborted on error unless
  `leavePartsOnError`). Streams are read part by part, so at most two parts are held in memory.
  `Location` is the object URL for a single PUT and the `<Location>` S3 returns for a multipart
  upload, which is what upstream's URL fallbacks (IONOS, MinIO) handle.
- **Endpoints**: `https://<bucket>.s3.<region>.amazonaws.com` (`.amazonaws.com.cn` in `cn-*`);
  custom `endpoint` (with or without a scheme, port and path kept); virtual-hosted style unless
  `forcePathStyle`, a bucket name that is not DNS compatible (or dotted, over https), or an IP /
  `localhost` endpoint.
- **Checksums**: `providerConfig.checksumAlgorithm` (`CRC32`, `CRC32C`, `SHA1`, `SHA256`,
  `CRC64NVME`) sends `x-amz-checksum-*` on `PutObject` / each part (CRC64NVME multipart uploads
  declare `x-amz-checksum-type: FULL_OBJECT`). Without it, `PutObject` sends a CRC32 like the SDK's
  default `requestChecksumCalculation: 'WHEN_SUPPORTED'`; `'WHEN_REQUIRED'` (option or
  `AWS_REQUEST_CHECKSUM_CALCULATION`) turns that off.
- **Errors**: `S3ServiceException` (extends `ApplicationError`) with `name` = the S3 error `Code`
  (`NotFound` for a body-less 404), `status` / `metadata['httpStatusCode']` = the HTTP status.
  `CompleteMultipartUpload` errors returned with a 200 are thrown too.
- **Credentials / region**: `s3Options.credentials` (or the deprecated root-level keys), else the
  environment: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`, `AWS_REGION`.
- `requestHandler.requestTimeout` (ms) sets the HTTP timeout (default 300 s; the SDK has none).

### Not replicated from the AWS SDK

- The rest of the default credential chain: shared `~/.aws/credentials` / `~/.aws/config` files
  and `AWS_PROFILE`, SSO, `credential_process`, web identity (`AWS_WEB_IDENTITY_TOKEN_FILE`), ECS
  container credentials and EC2 instance metadata (IMDS). Use `credentials` (a closure can fetch
  and cache temporary credentials) or the env vars. `AWS_DEFAULT_REGION` is not read (the SDK
  doesn't either).
- Concurrent part uploads (`queueSize` is accepted, parts go one at a time), retries with backoff,
  clock-skew correction, `useAccelerateEndpoint`, `useDualstackEndpoint`, `useFipsEndpoint`, ARN /
  access-point buckets, S3 Express directory buckets, and `aws-chunked` streaming signatures (each
  request body is buffered and hashed).
- Presigned URLs carry only the query-string `GetObject` members (`Response*`, `VersionId`,
  `PartNumber`); header members (e.g. SSE-C keys) are not signed into them.
- The upload plugin passes the Strapi instance to `init()`; without it (or without a `fetch`
  service) requests fail with an explicit error. Warnings go to the PHP error log
  (`Utils::$warningHandler` replaces the sink).
