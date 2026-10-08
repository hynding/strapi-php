<?php

declare(strict_types=1);

namespace Strapi\Provider\UploadAwsS3;

/**
 * PHP-port addition: AWS Signature Version 4 (the part of `@smithy/signature-v4` the provider
 * needs), for header-signed requests and query-string presigned URLs.
 *
 * S3 flavour: the canonical URI is the request path exactly as sent (already URI-encoded once by
 * the caller, never normalised nor double-encoded), the payload hash is the hex SHA-256 of the
 * body, or `UNSIGNED-PAYLOAD` for presigned URLs.
 *
 * @phpstan-type Credentials array{accessKeyId: string, secretAccessKey: string, sessionToken?: string}
 */
final class SignatureV4
{
    public const string ALGORITHM = 'AWS4-HMAC-SHA256';

    public const string UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

    /** SigV4 presigned URLs can't outlive a week (`@smithy/signature-v4` MAX_PRESIGNED_TTL). */
    public const int MAX_PRESIGNED_TTL = 604800;

    public function __construct(private readonly string $service, private readonly string $region)
    {
    }

    /**
     * Signs a request with an `Authorization` header. `$headers` must contain `host`; `x-amz-date`
     * (and `x-amz-security-token` for temporary credentials) are added. Every header passed is
     * signed.
     *
     * @param array<string, string> $headers
     * @param Credentials $credentials
     * @return array<string, string> the headers to send
     */
    public function sign(string $method, string $url, array $headers, string $payloadHash, array $credentials, \DateTimeInterface $date): array
    {
        $amzDate = self::amzDate($date);
        $headers = array_change_key_case($headers, CASE_LOWER);
        $headers['x-amz-date'] = $amzDate;
        if (($credentials['sessionToken'] ?? '') !== '') {
            $headers['x-amz-security-token'] = (string) $credentials['sessionToken'];
        }

        [$canonicalHeaders, $signedHeaders] = self::canonicalHeaders($headers);
        $canonicalRequest = self::canonicalRequest($method, $url, $canonicalHeaders, $signedHeaders, $payloadHash);
        $scope = $this->scope($date);
        $signature = $this->signature($canonicalRequest, $amzDate, $scope, $credentials['secretAccessKey'], $date);

        $headers['authorization'] = self::ALGORITHM
            . " Credential={$credentials['accessKeyId']}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        return $headers;
    }

    /**
     * Query-string authentication (`@aws-sdk/s3-request-presigner`): only `host` is signed and the
     * payload is `UNSIGNED-PAYLOAD`. Query parameters already in `$url` are part of the signature.
     *
     * @param Credentials $credentials
     */
    public function presign(string $method, string $url, array $credentials, int $expiresIn, \DateTimeInterface $date): string
    {
        if ($expiresIn > self::MAX_PRESIGNED_TTL) {
            throw new \InvalidArgumentException('Signature version 4 presigned URLs must have an expiration date less than one week in the future');
        }

        $amzDate = self::amzDate($date);
        $scope = $this->scope($date);
        $query = [
            'X-Amz-Algorithm' => self::ALGORITHM,
            'X-Amz-Credential' => "{$credentials['accessKeyId']}/{$scope}",
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) $expiresIn,
            'X-Amz-SignedHeaders' => 'host',
        ];
        if (($credentials['sessionToken'] ?? '') !== '') {
            $query['X-Amz-Security-Token'] = (string) $credentials['sessionToken'];
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        $url .= $separator . self::encodeQuery($query);

        [$canonicalHeaders, $signedHeaders] = self::canonicalHeaders(['host' => self::hostOf($url)]);
        $canonicalRequest = self::canonicalRequest($method, $url, $canonicalHeaders, $signedHeaders, self::UNSIGNED_PAYLOAD);
        $signature = $this->signature($canonicalRequest, $amzDate, $scope, $credentials['secretAccessKey'], $date);

        return $url . '&X-Amz-Signature=' . $signature;
    }

    /** `Host` header value of a URL: the host, plus the port when it is not the scheme's default. */
    public static function hostOf(string $url): string
    {
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        $port = $parts['port'] ?? null;
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if ($port !== null && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $host .= ':' . $port;
        }

        return $host;
    }

    /** RFC 3986 encoding (`A-Za-z0-9-_.~` kept), as SigV4 requires. */
    public static function uriEncode(string $value): string
    {
        return rawurlencode($value);
    }

    /** @param array<string, string> $query */
    public static function encodeQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            $pairs[] = self::uriEncode((string) $key) . '=' . self::uriEncode($value);
        }

        return implode('&', $pairs);
    }

    public static function amzDate(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /**
     * @param array<string, string> $headers lower-case names
     * @return array{0: string, 1: string} canonical headers block, signed headers list
     */
    public static function canonicalHeaders(array $headers): array
    {
        $canonical = [];
        foreach ($headers as $name => $value) {
            $canonical[strtolower(trim((string) $name))] = (string) preg_replace('/\s+/', ' ', trim($value));
        }
        ksort($canonical, SORT_STRING);

        $lines = '';
        foreach ($canonical as $name => $value) {
            $lines .= "{$name}:{$value}\n";
        }

        return [$lines, implode(';', array_keys($canonical))];
    }

    public static function canonicalRequest(string $method, string $url, string $canonicalHeaders, string $signedHeaders, string $payloadHash): string
    {
        $parts = parse_url($url);
        $path = (string) ($parts['path'] ?? '');
        if ($path === '') {
            $path = '/';
        }

        return implode("\n", [
            strtoupper($method),
            $path,
            self::canonicalQuery((string) ($parts['query'] ?? '')),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);
    }

    /** Query parameters sorted by encoded name then value, each encoded once. */
    public static function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = [self::uriEncode(rawurldecode($key)), self::uriEncode(rawurldecode($value))];
        }
        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(static fn (array $p): string => "{$p[0]}={$p[1]}", $pairs));
    }

    public function stringToSign(string $canonicalRequest, string $amzDate, string $scope): string
    {
        return implode("\n", [self::ALGORITHM, $amzDate, $scope, hash('sha256', $canonicalRequest)]);
    }

    private function scope(\DateTimeInterface $date): string
    {
        return substr(self::amzDate($date), 0, 8) . "/{$this->region}/{$this->service}/aws4_request";
    }

    private function signature(string $canonicalRequest, string $amzDate, string $scope, string $secretAccessKey, \DateTimeInterface $date): string
    {
        $kDate = hash_hmac('sha256', substr(self::amzDate($date), 0, 8), 'AWS4' . $secretAccessKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $this->service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        return hash_hmac('sha256', $this->stringToSign($canonicalRequest, $amzDate, $scope), $kSigning);
    }
}
