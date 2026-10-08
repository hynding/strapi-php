<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailAmazonSes;

/**
 * Not an upstream file: AWS Signature Version 4 (`@smithy/signature-v4`) for one request — signs
 * every given header plus `host` and `x-amz-date` (and `x-amz-security-token` for temporary
 * credentials) and returns the headers to send, `authorization` included.
 */
final class SignatureV4
{
    /**
     * @param array<string, string> $headers
     * @param array{accessKeyId: string, secretAccessKey: string, sessionToken?: string|null} $credentials
     * @return array<string, string>
     */
    public static function sign(
        string $method,
        string $url,
        array $headers,
        string $body,
        string $service,
        string $region,
        array $credentials,
        ?\DateTimeInterface $now = null,
    ): array {
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $host .= ':' . $parts['port'];
        }
        $path = (string) ($parts['path'] ?? '/');
        $path = $path === '' ? '/' : $path;
        $query = (string) ($parts['query'] ?? '');

        $time = $now !== null ? \DateTimeImmutable::createFromInterface($now) : new \DateTimeImmutable();
        $time = $time->setTimezone(new \DateTimeZone('UTC'));
        $amzDate = $time->format('Ymd\THis\Z');
        $date = $time->format('Ymd');

        $signed = [];
        foreach ($headers as $name => $value) {
            $signed[strtolower($name)] = trim((string) preg_replace('/\s+/', ' ', $value));
        }
        $signed['host'] = $host;
        $signed['x-amz-date'] = $amzDate;
        if (($credentials['sessionToken'] ?? null) !== null && $credentials['sessionToken'] !== '') {
            $signed['x-amz-security-token'] = $credentials['sessionToken'];
        }
        ksort($signed);

        $canonicalHeaders = '';
        foreach ($signed as $name => $value) {
            $canonicalHeaders .= "{$name}:{$value}\n";
        }
        $signedHeaders = implode(';', array_keys($signed));

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            self::canonicalPath($path),
            self::canonicalQuery($query),
            $canonicalHeaders,
            $signedHeaders,
            hash('sha256', $body),
        ]);

        $scope = "{$date}/{$region}/{$service}/aws4_request";
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest)]);

        $kDate = hash_hmac('sha256', $date, 'AWS4' . $credentials['secretAccessKey'], true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $out = [];
        foreach ($signed as $name => $value) {
            if ($name !== 'host') {
                $out[$name] = $value;
            }
        }
        $out['authorization'] = "AWS4-HMAC-SHA256 Credential={$credentials['accessKeyId']}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        return $out;
    }

    private static function canonicalPath(string $path): string
    {
        return implode('/', array_map(static fn (string $s): string => rawurlencode(rawurldecode($s)), explode('/', $path)));
    }

    private static function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = [rawurlencode(rawurldecode($k)), rawurlencode(rawurldecode($v))];
        }
        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(static fn (array $p): string => "{$p[0]}={$p[1]}", $pairs));
    }
}
