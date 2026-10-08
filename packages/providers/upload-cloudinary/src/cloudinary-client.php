<?php

declare(strict_types=1);

namespace Strapi\Provider\UploadCloudinary;

/**
 * PHP-port addition: the slice of the `cloudinary` Node SDK (v2) the provider uses —
 * `cloudinary.config(options)`, `uploader.upload_stream`, `uploader.upload_chunked_stream`,
 * `uploader.destroy` and `cloudinary.url` — over core's `strapi.fetch`
 * (`Strapi\Core\Utils\Fetch`).
 *
 * Requests are signed as the SDK does (`api_sign_request`): the non-blank upload parameters,
 * sorted by name, joined as `k=v` with `&` (signature version 2 escapes `&` inside a pair as
 * `%26`), followed by the API secret, hashed with SHA-1 (or SHA-256 with
 * `signature_algorithm: 'sha256'`).
 *
 * Config keys (`cloudinary.config()` names): `cloud_name`, `api_key`, `api_secret`, `secure`,
 * `private_cdn`, `secure_distribution`, `upload_prefix`, `signature_algorithm`,
 * `signature_version`, `timeout` (ms). `CLOUDINARY_URL`
 * (`cloudinary://<api_key>:<api_secret>@<cloud_name>`) is read from the environment, as the SDK
 * does, and explicit options win over it.
 *
 * @phpstan-type FetchResponse array{ok: bool, status: int, headers: array<string, string>, body: string}
 * @phpstan-type FetchFn \Closure(string, array{method?: string, headers?: array<string, string>, body?: string|null, timeout?: int|float}): FetchResponse
 */
class CloudinaryClient
{
    public const string UPLOAD_PREFIX = 'https://api.cloudinary.com';

    public const string SHARED_CDN = 'res.cloudinary.com';

    /** `Chunkable`'s default `chunk_size`. */
    public const int CHUNK_SIZE = 20000000;

    /** `build_upload_params`: parameters sent as-is. */
    private const array STRING_PARAMS = [
        'access_mode', 'asset_folder', 'callback', 'display_name', 'eval', 'folder', 'format', 'filename_override',
        'moderation', 'notification_url', 'proxy', 'public_id', 'public_id_prefix', 'type', 'upload_preset',
        'quality_override', 'on_success', 'eager_notification_url', 'transformation', 'eager', 'responsive_breakpoints',
        'auto_tagging', 'background_removal', 'categorization', 'detection', 'ocr', 'raw_convert', 'similarity_search',
        'unique_display_name',
    ];

    /** `build_upload_params`: parameters sent through `as_safe_bool` (true → 1, false → 0). */
    private const array BOOL_PARAMS = [
        'async', 'backup', 'cinemagraph_analysis', 'colors', 'discard_original_filename', 'eager_async', 'exif', 'faces',
        'image_metadata', 'media_metadata', 'invalidate', 'overwrite', 'phash', 'quality_analysis', 'return_delete_token',
        'unique_filename', 'use_filename', 'use_filename_as_display_name', 'accessibility_analysis',
        'use_asset_folder_as_public_id_prefix', 'visual_search', 'auto_chaptering', 'auto_transcription',
    ];

    /** `generate_transformation_string`: option => URL parameter, for the options this port supports. */
    private const array TRANSFORMATION_PARAMS = [
        'angle' => 'a', 'aspect_ratio' => 'ar', 'background' => 'b', 'crop' => 'c', 'default_image' => 'd',
        'delay' => 'dl', 'density' => 'dn', 'dpr' => 'dpr', 'effect' => 'e', 'end_offset' => 'eo',
        'fetch_format' => 'f', 'flags' => 'fl', 'fps' => 'fps', 'gravity' => 'g', 'height' => 'h', 'opacity' => 'o',
        'page' => 'pg', 'quality' => 'q', 'radius' => 'r', 'start_offset' => 'so', 'video_codec' => 'vc',
        'video_sampling' => 'vs', 'width' => 'w', 'x' => 'x', 'y' => 'y', 'zoom' => 'z',
    ];

    /** @var array<string, mixed> */
    public readonly array $config;

    /** @var FetchFn|null */
    private readonly ?\Closure $fetch;

    private readonly \Closure $now;

    private readonly \Closure $randomId;

    /**
     * @param array<string, mixed> $config `cloudinary.config(options)`
     * @param callable|null $fetch `strapi.fetch`
     * @param (\Closure(): int)|null $now unix timestamp (tests)
     * @param (\Closure(): string)|null $randomId `random_public_id()`, also the multipart boundary (tests)
     */
    public function __construct(array $config = [], ?callable $fetch = null, ?\Closure $now = null, ?\Closure $randomId = null)
    {
        $this->config = [...self::configFromEnv(), ...$config];
        /** @var FetchFn|null $closure */
        $closure = $fetch !== null ? \Closure::fromCallable($fetch) : null;
        $this->fetch = $closure;
        $this->now = $now ?? static fn (): int => time();
        $this->randomId = $randomId ?? static fn (): string => (string) preg_replace('/[^a-z0-9]/', '', base64_encode(random_bytes(12)));
    }

    /**
     * `CLOUDINARY_URL=cloudinary://<api_key>:<api_secret>@<cloud_name>[/<secure_distribution>][?key=value…]`
     *
     * @return array<string, mixed>
     */
    public static function configFromEnv(): array
    {
        $url = getenv('CLOUDINARY_URL');
        if (!is_string($url) || !str_starts_with($url, 'cloudinary://')) {
            return [];
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return [];
        }

        $config = [
            'cloud_name' => $parts['host'] ?? null,
            'api_key' => isset($parts['user']) ? rawurldecode($parts['user']) : null,
            'api_secret' => isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
        ];
        $path = $parts['path'] ?? '';
        if ($path !== '' && $path !== '/') {
            $config['private_cdn'] = true;
            $config['secure_distribution'] = substr($path, 1);
        }
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach ($query as $key => $value) {
                $config[(string) $key] = $value;
            }
        }

        return array_filter($config, static fn (mixed $v): bool => $v !== null);
    }

    /**
     * `uploader.upload_stream(options, callback)` fed with the whole body: one signed
     * multipart POST to `/v1_1/<cloud_name>/<resource_type>/upload`.
     *
     * @param string|resource $body
     * @param array<string, mixed> $options
     * @return array<string, mixed> the upload API result
     */
    public function uploadStream($body, array $options = []): array
    {
        $data = is_string($body) ? $body : (string) stream_get_contents($body);
        $options = [...$this->config, ...$options];
        $params = self::buildUploadParams($options, ($this->now)());

        return $this->callApi('upload', $params, $options, $data);
    }

    /**
     * `uploader.upload_chunked_stream(options, callback)`: the body is sent in `chunk_size`
     * (default 20 000 000 bytes) parts, each a signed upload request carrying
     * `Content-Range: bytes <start>-<end>/<total or -1>` and the same `X-Unique-Upload-Id`; the last
     * response is the upload result.
     *
     * @param string|resource $body
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function uploadChunkedStream($body, array $options = []): array
    {
        $options = [...$this->config, ...$options];
        $uniqueUploadId = ($this->randomId)();
        $chunkSize = $options['chunk_size'] ?? $options['part_size'] ?? self::CHUNK_SIZE;
        $chunkSize = is_numeric($chunkSize) && (int) $chunkSize > 0 ? (int) $chunkSize : self::CHUNK_SIZE;

        $chunks = self::chunks($body, $chunkSize);
        $sent = 0;
        $result = [];
        $chunk = (string) $chunks->current();
        $chunks->next();
        while (true) {
            $isLast = !$chunks->valid();
            $start = $sent;
            $sent += strlen($chunk);
            $range = sprintf('bytes %d-%d/%d', $start, $sent - 1, $isLast ? $sent : -1);

            $params = self::buildUploadParams($options, ($this->now)());
            $result = $this->callApi('upload', $params, $options, $chunk, [
                'Content-Range' => $range,
                'X-Unique-Upload-Id' => $uniqueUploadId,
            ]);

            if ($isLast) {
                return $result;
            }
            $chunk = (string) $chunks->current();
            $chunks->next();
        }
    }

    /**
     * `uploader.destroy(public_id, options)`.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed> `{ result: 'ok' | 'not found' | … }`
     */
    public function destroy(string $publicId, array $options = []): array
    {
        $options = [...$this->config, ...$options];
        $params = [
            'timestamp' => ($this->now)(),
            'type' => $options['type'] ?? null,
            'invalidate' => self::asSafeBool($options['invalidate'] ?? null),
            'public_id' => $publicId,
            'notification_url' => $options['notification_url'] ?? null,
        ];

        return $this->callApi('destroy', $params, $options);
    }

    /**
     * `cloudinary.url(public_id, options)` for delivery URLs with simple transformations
     * (`crop`, `width`, `height`, `delay`, `video_sampling`…). Not replicated: named / chained
     * transformations, layers, URL signing, `auth_token`, CNAMEs and the `_a` analytics parameter.
     *
     * @param array<string, mixed> $options
     */
    public function url(string $publicId, array $options = []): string
    {
        $options = [...$this->config, ...$options];
        $cloudName = self::ensure($options, 'cloud_name');
        $resourceType = is_string($options['resource_type'] ?? null) ? $options['resource_type'] : 'image';
        $type = is_string($options['type'] ?? null) ? $options['type'] : 'upload';

        $transformation = [];
        foreach (self::TRANSFORMATION_PARAMS as $option => $param) {
            $value = $options[$option] ?? null;
            if ($value !== null && $value !== '' && is_scalar($value)) {
                $transformation[] = "{$param}_" . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            }
        }
        sort($transformation);

        $source = (string) preg_replace('~([^:])//~', '$1/', $publicId);
        $source = strtr(self::encodeURIComponent(rawurldecode($source)), ['%3A' => ':', '%2F' => '/']);
        if (is_string($options['format'] ?? null) && $options['format'] !== '') {
            $source .= '.' . $options['format'];
        }

        $version = $options['version'] ?? null;
        $forceVersion = $options['force_version'] ?? true;
        if ($version === null && $forceVersion !== false && str_contains($source, '/') && preg_match('~^v[0-9]+~', $source) !== 1) {
            $version = 1;
        }

        $secure = ($options['secure'] ?? true) !== false;
        $privateCdn = (bool) ($options['private_cdn'] ?? false);
        if ($secure) {
            $distribution = is_string($options['secure_distribution'] ?? null) && $options['secure_distribution'] !== ''
                ? $options['secure_distribution']
                : ($privateCdn ? "{$cloudName}-res.cloudinary.com" : self::SHARED_CDN);
            $prefix = "https://{$distribution}";
        } else {
            $prefix = 'http://' . ($privateCdn ? "{$cloudName}-" : '') . 'res.cloudinary.com';
        }
        if (!$privateCdn) {
            $prefix .= "/{$cloudName}";
        }

        $parts = [$prefix, $resourceType, $type, implode(',', $transformation), $version !== null && is_scalar($version) ? 'v' . $version : null, $source];

        return str_replace(' ', '%20', implode('/', array_filter($parts, static fn (?string $p): bool => $p !== null && $p !== '')));
    }

    /**
     * `utils.build_upload_params(options)` for scalar options: strings as-is, booleans through
     * `as_safe_bool`, `tags` / `allowed_formats` arrays joined with commas, `context` / `metadata`
     * arrays encoded as `key=value|…`, `headers` as `Name: value` lines, `access_control` /
     * `regions` as JSON.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function buildUploadParams(array $options, int $timestamp): array
    {
        $params = ['timestamp' => $options['timestamp'] ?? $timestamp];
        foreach (self::STRING_PARAMS as $key) {
            if (isset($options[$key]) && is_scalar($options[$key])) {
                $params[$key] = $options[$key];
            }
        }
        foreach (self::BOOL_PARAMS as $key) {
            if (array_key_exists($key, $options)) {
                $params[$key] = self::asSafeBool($options[$key]);
            }
        }
        foreach (['tags', 'allowed_formats'] as $key) {
            if (isset($options[$key])) {
                $params[$key] = is_array($options[$key]) ? implode(',', array_map(self::scalar(...), $options[$key])) : $options[$key];
            }
        }
        foreach (['context', 'metadata'] as $key) {
            if (isset($options[$key])) {
                $params[$key] = is_array($options[$key]) ? self::encodeContext($options[$key]) : $options[$key];
            }
        }
        if (isset($options['headers'])) {
            $headers = $options['headers'];
            $params['headers'] = is_array($headers)
                ? implode("\n", array_is_list($headers) ? array_map(self::scalar(...), $headers) : array_map(static fn (string|int $k, mixed $v): string => "{$k}:" . self::scalar($v), array_keys($headers), $headers))
                : $headers;
        }
        foreach (['access_control', 'regions'] as $key) {
            if (isset($options[$key]) && $options[$key] !== '') {
                $value = $options[$key];
                if ($key === 'access_control' && is_array($value) && !array_is_list($value)) {
                    $value = [$value];
                }
                $params[$key] = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES);
            }
        }

        return $params;
    }

    /**
     * `api_sign_request(params_to_sign, api_secret, signature_algorithm, signature_version)`.
     *
     * @param array<string, mixed> $params
     */
    public static function apiSignRequest(array $params, string $apiSecret, string $algorithm = 'sha1', int $version = 2): string
    {
        if (!in_array($algorithm, ['sha1', 'sha256'], true)) {
            throw new \InvalidArgumentException("Signature algorithm {$algorithm} is not supported. Supported algorithms: sha1, sha256");
        }

        return hash($algorithm, self::apiStringToSign($params, $version) . $apiSecret);
    }

    /** @param array<string, mixed> $params */
    public static function apiStringToSign(array $params, int $version = 2): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            $value = is_array($value) ? implode(',', array_map(self::scalar(...), $value)) : $value;
            if ($value === null || $value === '') {
                continue;
            }
            $pairs[(string) $key] = (string) $key . '=' . self::scalar($value);
        }
        ksort($pairs, SORT_STRING);

        return implode('&', array_map(static fn (string $pair): string => $version >= 2 ? str_replace('&', '%26', $pair) : $pair, $pairs));
    }

    /**
     * `call_api(action, …)`: signs the parameters (`sign_request`), posts them as
     * `multipart/form-data` (with the file part when `$file` is given) and returns the decoded
     * result, or throws {@see CloudinaryError}.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function callApi(string $action, array $params, array $options, ?string $file = null, array $headers = []): array
    {
        if ($this->fetch === null) {
            throw new \RuntimeException('Cloudinary upload provider: no HTTP client (the provider needs `strapi.fetch`; pass the Strapi instance to init())');
        }

        $params = $this->signRequest($params, $options);

        $resourceType = is_string($options['resource_type'] ?? null) && $options['resource_type'] !== '' ? $options['resource_type'] : 'image';
        $uploadPrefix = is_string($options['upload_prefix'] ?? null) && $options['upload_prefix'] !== '' ? $options['upload_prefix'] : self::UPLOAD_PREFIX;
        $url = implode('/', [rtrim($uploadPrefix, '/'), 'v1_1', self::ensure($options, 'cloud_name'), rawurlencode($resourceType), rawurlencode($action)]);

        $boundary = ($this->randomId)();
        $body = '';
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$key}\"\r\n\r\n" . self::scalar($value) . "\r\n";
        }
        if ($file !== null) {
            $filename = is_string($options['filename'] ?? null) && $options['filename'] !== '' ? $options['filename'] : 'file';
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\nContent-Type: application/octet-stream\r\n\r\n";
            $body .= $file . "\r\n";
        }
        $body .= "--{$boundary}--";

        $timeout = $options['timeout'] ?? null;
        try {
            $response = ($this->fetch)($url, [
                'method' => 'POST',
                'headers' => [
                    'Content-Type' => "multipart/form-data; boundary={$boundary}",
                    'Content-Length' => (string) strlen($body),
                    ...$headers,
                ],
                'body' => $body,
                'timeout' => is_numeric($timeout) && $timeout > 0 ? $timeout / 1000 : 60,
            ]);
        } catch (\RuntimeException $error) {
            throw new CloudinaryError($error->getMessage(), 0, 'Error', $error);
        }

        $status = $response['status'];
        if (!in_array($status, [200, 400, 401, 404, 420, 500], true)) {
            throw new CloudinaryError("Server returned unexpected status code - {$status}", $status, 'UnexpectedResponse');
        }

        $result = json_decode($response['body'], true);
        if (!is_array($result)) {
            throw new CloudinaryError("Server return invalid JSON response. Status Code {$status}.", $status);
        }
        if (isset($result['error'])) {
            $error = $result['error'];
            $message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : (is_string($error) ? $error : 'Unknown error');
            $name = is_array($error) && is_string($error['name'] ?? null) ? $error['name'] : 'Error';

            throw new CloudinaryError($message, $status, $name);
        }

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * `sign_request(params, options)`: drops blank values, adds `signature` then `api_key`.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function signRequest(array $params, array $options): array
    {
        $apiKey = self::ensure($options, 'api_key');
        $apiSecret = self::ensure($options, 'api_secret');
        $algorithm = is_string($options['signature_algorithm'] ?? null) ? $options['signature_algorithm'] : 'sha1';
        $version = is_numeric($options['signature_version'] ?? null) ? (int) $options['signature_version'] : 2;

        $params = array_filter($params, static fn (mixed $v): bool => $v !== null && self::scalar($v) !== '');
        $params['signature'] = self::apiSignRequest($params, $apiSecret, $algorithm, $version);
        $params['api_key'] = $apiKey;

        return $params;
    }

    /** @param array<string, mixed> $options */
    private static function ensure(array $options, string $key): string
    {
        $value = $options[$key] ?? null;
        if (!is_scalar($value) || (string) $value === '') {
            throw new \InvalidArgumentException("Must supply {$key}");
        }

        return (string) $value;
    }

    /** `as_safe_bool`: true / 'true' / '1' → 1, false / 'false' / '0' → 0, anything else unchanged. */
    private static function asSafeBool(mixed $value): mixed
    {
        return match (true) {
            $value === null => null,
            $value === true, $value === 'true', $value === '1' => 1,
            $value === false, $value === 'false', $value === '0' => 0,
            default => $value,
        };
    }

    /**
     * `encode_context`: `key=value|key2=["a","b"]`, `=` and `|` escaped with a backslash.
     *
     * @param array<mixed> $context
     */
    private static function encodeContext(array $context): string
    {
        $escape = static fn (mixed $v): string => (string) preg_replace('/([=|])/', '\\\\$1', self::scalar($v));
        $pairs = [];
        foreach ($context as $key => $value) {
            $pairs[] = is_array($value)
                ? "{$key}=[" . implode(',', array_map(static fn (mixed $v): string => '"' . $escape($v) . '"', $value)) . ']'
                : "{$key}=" . $escape($value);
        }

        return implode('|', $pairs);
    }

    private static function encodeURIComponent(string $value): string
    {
        return strtr(rawurlencode($value), ['%21' => '!', '%27' => "'", '%28' => '(', '%29' => ')', '%2A' => '*']);
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value === true => 'true',
            $value === false => 'false',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /**
     * @param string|resource $body
     * @return \Generator<int, string>
     */
    private static function chunks($body, int $size): \Generator
    {
        if (is_string($body)) {
            $length = strlen($body);
            for ($offset = 0; $offset === 0 || $offset < $length; $offset += $size) {
                yield substr($body, $offset, $size);
            }

            return;
        }

        $yielded = false;
        while (true) {
            $chunk = '';
            while (strlen($chunk) < $size && !feof($body)) {
                $read = fread($body, max(1, $size - strlen($chunk)));
                if ($read === false) {
                    throw new \RuntimeException('Unable to read the upload stream');
                }
                if ($read === '' && feof($body)) {
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
}
