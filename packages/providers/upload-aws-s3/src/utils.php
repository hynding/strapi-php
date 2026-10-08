<?php

declare(strict_types=1);

namespace Strapi\Provider\UploadAwsS3;

/**
 * Port of packages/providers/upload-aws-s3/src/utils.ts.
 *
 * PHP-port addition: `emitWarning()`, the sink for upstream's `process.emitWarning` /
 * `console.warn` (the PHP error log by default; `$warningHandler` replaces it, e.g. in tests).
 */
final class Utils
{
    public const string ENDPOINT_PATTERN = '/^(.+\.)?s3[.-]([a-z0-9-]+)\./';

    /** @var (\Closure(string): void)|null */
    public static ?\Closure $warningHandler = null;

    public static function emitWarning(string $message): void
    {
        if (self::$warningHandler !== null) {
            (self::$warningHandler)($message);

            return;
        }

        error_log("Warning: {$message}");
    }

    public static function isUrlFromBucket(string $fileUrl, string $bucketName, string $baseUrl = ''): bool
    {
        $url = self::parseUrl($fileUrl);
        if ($url === null) {
            return false;
        }

        if ($baseUrl !== '') {
            return false;
        }

        $bucket = self::getBucketFromAwsUrl($url)['bucket'] ?? null;
        if ($bucket !== null && $bucket !== '') {
            return $bucket === $bucketName;
        }

        return str_starts_with($url['host'], "{$bucketName}.") || str_contains($url['pathname'], "/{$bucketName}/");
    }

    /**
     * Parse the bucket name from a URL.
     * See all URL formats in https://docs.aws.amazon.com/AmazonS3/latest/userguide/access-bucket-intro.html
     *
     * @param array{protocol: string, host: string, pathname: string, href: string} $url
     * @return array{bucket?: string|null, err?: string}
     */
    private static function getBucketFromAwsUrl(array $url): array
    {
        // S3://<bucket-name>/<key>
        if ($url['protocol'] === 's3:') {
            $bucket = $url['host'];
            if ($bucket === '') {
                return ['err' => "Invalid S3 url: no bucket: {$url['href']}"];
            }

            return ['bucket' => $bucket];
        }

        if ($url['host'] === '') {
            return ['err' => "Invalid S3 url: no hostname: {$url['href']}"];
        }

        if (preg_match(self::ENDPOINT_PATTERN, $url['host'], $matches) !== 1) {
            return ['err' => "Invalid S3 url: hostname does not appear to be a valid S3 endpoint: {$url['href']}"];
        }

        $prefix = $matches[1];

        // https://s3.amazonaws.com/<bucket-name>
        if ($prefix === '') {
            if ($url['pathname'] === '/') {
                return ['bucket' => null];
            }

            $index = strpos($url['pathname'], '/', 1);

            // https://s3.amazonaws.com/<bucket-name>
            if ($index === false) {
                return ['bucket' => substr($url['pathname'], 1)];
            }

            // https://s3.amazonaws.com/<bucket-name>/ and https://s3.amazonaws.com/<bucket-name>/key
            return ['bucket' => substr($url['pathname'], 1, $index - 1)];
        }

        // https://<bucket-name>.s3.amazonaws.com/
        return ['bucket' => substr($prefix, 0, -1)];
    }

    /**
     * `extractCredentials(options)`: a credential provider (a closure) is returned unchanged so it
     * is resolved on every request; a `credentials` array is normalised; deprecated root-level
     * `accessKeyId` / `secretAccessKey` in `s3Options` are still read, with a warning.
     *
     * @param array<string, mixed> $options
     * @return array{accessKeyId: mixed, secretAccessKey: mixed, sessionToken?: mixed}|\Closure|null
     */
    public static function extractCredentials(array $options): array|\Closure|null
    {
        $s3Options = is_array($options['s3Options'] ?? null) ? $options['s3Options'] : null;
        $credentials = $s3Options['credentials'] ?? null;

        // If a credential provider function is supplied, pass it through unchanged so the
        // client resolves (and refreshes) credentials at runtime, rather than capturing a
        // single static value once at init.
        if ($credentials instanceof \Closure) {
            return $credentials;
        }
        if (is_callable($credentials) && !is_string($credentials) && !is_array($credentials)) {
            return \Closure::fromCallable($credentials);
        }

        if (is_array($credentials) && $credentials !== []) {
            return [
                'accessKeyId' => $credentials['accessKeyId'] ?? null,
                'secretAccessKey' => $credentials['secretAccessKey'] ?? null,
                ...(!empty($credentials['sessionToken']) ? ['sessionToken' => $credentials['sessionToken']] : []),
            ];
        }

        // Support root-level accessKeyId/secretAccessKey in s3Options for backwards
        // compatibility. They belong in a `credentials` array.
        if (!empty($s3Options['accessKeyId']) && !empty($s3Options['secretAccessKey'])) {
            self::emitWarning(
                "[upload-aws-s3] Passing 'accessKeyId' and 'secretAccessKey' directly in s3Options is deprecated. "
                . "Please wrap them in a 'credentials' object: s3Options: { credentials: { accessKeyId, secretAccessKey } }."
            );

            return [
                'accessKeyId' => $s3Options['accessKeyId'],
                'secretAccessKey' => $s3Options['secretAccessKey'],
            ];
        }

        return null;
    }

    /**
     * The parts of a WHATWG `new URL()` the checks above read; null where `new URL()` throws
     * (no scheme, or a special scheme without a host).
     *
     * @return array{protocol: string, host: string, pathname: string, href: string}|null
     */
    private static function parseUrl(string $value): ?array
    {
        $value = trim($value);
        if (preg_match('~^([a-zA-Z][a-zA-Z0-9+.\-]*):(.*)$~s', $value, $m) !== 1) {
            return null;
        }
        $protocol = strtolower($m[1]) . ':';
        $rest = $m[2];
        $special = in_array($protocol, ['http:', 'https:', 'ws:', 'wss:', 'ftp:', 'file:'], true);

        $host = '';
        $pathname = $rest;
        if (str_starts_with($rest, '//') || ($special && $protocol !== 'file:')) {
            $rest = ltrim($rest, '/\\');
            $end = strcspn($rest, '/?#\\');
            $authority = substr($rest, 0, $end);
            $pathname = substr($rest, $end);
            $at = strrpos($authority, '@');
            $host = $at !== false ? substr($authority, $at + 1) : $authority;
            if ($special) {
                $host = strtolower($host);
                if ($host === '' || preg_match('~[\s<>^|%]~', $host) === 1) {
                    return null;
                }
                // default ports are dropped from `host`
                $host = (string) preg_replace($protocol === 'https:' ? '~:443$~' : '~:80$~', '', $host);
            }
        }
        $pathname = (string) preg_replace('~[?#].*$~s', '', $pathname);
        if ($special && $pathname === '') {
            $pathname = '/';
        }

        return ['protocol' => $protocol, 'host' => $host, 'pathname' => $pathname, 'href' => $value];
    }
}
