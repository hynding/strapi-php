<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

/**
 * Minimal JSON Web Token sign/verify with the semantics of upstream's `jsonwebtoken`:
 * HS256/384/512 with any secret length (Strapi generates 16-byte secrets; no minimum is
 * enforced, unlike firebase/php-jwt 7), RS256/384/512 and ES256/384/512 via OpenSSL, and
 * the standard `exp` / `nbf` / `iat` checks with a small leeway.
 *
 * PHP-only: replaces the firebase/php-jwt dependency (6.x is blocked by a Packagist security
 * advisory, 7.x rejects short HMAC keys and would refuse secrets issued by Node Strapi).
 */
final class Jwt
{
    private const ALGORITHMS = [
        'HS256' => ['hmac', 'sha256'],
        'HS384' => ['hmac', 'sha384'],
        'HS512' => ['hmac', 'sha512'],
        'RS256' => ['rsa', OPENSSL_ALGO_SHA256],
        'RS384' => ['rsa', OPENSSL_ALGO_SHA384],
        'RS512' => ['rsa', OPENSSL_ALGO_SHA512],
        'ES256' => ['ec', OPENSSL_ALGO_SHA256, 64],
        'ES384' => ['ec', OPENSSL_ALGO_SHA384, 96],
        'ES512' => ['ec', OPENSSL_ALGO_SHA512, 132],
    ];

    /** Seconds of clock skew tolerated when checking exp / nbf / iat (jsonwebtoken's default is 0; a small value avoids flaky edge cases). */
    public static int $leeway = 0;

    /** @param array<string, mixed> $payload */
    public static function encode(array $payload, string $key, string $algorithm = 'HS256'): string
    {
        [$kind] = self::algorithm($algorithm);
        $header = self::base64UrlEncode(self::json(['alg' => $algorithm, 'typ' => 'JWT']));
        $body = self::base64UrlEncode(self::json($payload));
        $signature = self::sign("{$header}.{$body}", $key, $algorithm, $kind);

        return "{$header}.{$body}." . self::base64UrlEncode($signature);
    }

    /**
     * @return array<string, mixed>
     * @throws JwtException when the token is malformed, the signature is invalid or the time claims fail
     */
    public static function decode(string $token, string $key, string $algorithm = 'HS256', ?int $now = null): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new JwtException('Wrong number of segments', JwtException::MALFORMED);
        }
        [$header64, $body64, $signature64] = $parts;

        $header = json_decode(self::base64UrlDecode($header64), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== $algorithm) {
            throw new JwtException('Algorithm not allowed', JwtException::MALFORMED);
        }

        [$kind] = self::algorithm($algorithm);
        if (!self::verifySignature("{$header64}.{$body64}", self::base64UrlDecode($signature64), $key, $algorithm, $kind)) {
            throw new JwtException('Signature verification failed', JwtException::INVALID_SIGNATURE);
        }

        $payload = json_decode(self::base64UrlDecode($body64), true);
        if (!is_array($payload)) {
            throw new JwtException('Invalid claims encoding', JwtException::MALFORMED);
        }

        $now ??= time();
        if (isset($payload['nbf']) && is_numeric($payload['nbf']) && (int) $payload['nbf'] > $now + self::$leeway) {
            throw new JwtException('Cannot handle token prior to ' . date(DATE_ATOM, (int) $payload['nbf']), JwtException::BEFORE_VALID);
        }
        if (isset($payload['exp']) && is_numeric($payload['exp']) && $now - self::$leeway >= (int) $payload['exp']) {
            throw new JwtException('Expired token', JwtException::EXPIRED);
        }

        return $payload;
    }

    /** @return array{0: string, 1: string|int, 2?: int} */
    private static function algorithm(string $algorithm): array
    {
        if (!isset(self::ALGORITHMS[$algorithm])) {
            throw new \DomainException("Algorithm not supported: {$algorithm}");
        }

        return self::ALGORITHMS[$algorithm];
    }

    private static function sign(string $message, string $key, string $algorithm, string $kind): string
    {
        $spec = self::ALGORITHMS[$algorithm];
        if ($kind === 'hmac') {
            return hash_hmac((string) $spec[1], $message, $key, true);
        }

        $private = openssl_pkey_get_private($key);
        if ($private === false) {
            throw new \DomainException('OpenSSL unable to load the private key');
        }
        $signature = '';
        if (!openssl_sign($message, $signature, $private, (int) $spec[1])) {
            throw new \DomainException('OpenSSL unable to sign data');
        }
        if ($kind === 'ec') {
            $signature = self::derToRaw($signature, (int) ($spec[2] ?? 64));
        }

        return $signature;
    }

    private static function verifySignature(string $message, string $signature, string $key, string $algorithm, string $kind): bool
    {
        $spec = self::ALGORITHMS[$algorithm];
        if ($kind === 'hmac') {
            return hash_equals(hash_hmac((string) $spec[1], $message, $key, true), $signature);
        }

        $public = openssl_pkey_get_public($key);
        if ($public === false) {
            throw new \DomainException('OpenSSL unable to load the public key');
        }
        if ($kind === 'ec') {
            $signature = self::rawToDer($signature);
        }

        return openssl_verify($message, $signature, $public, (int) $spec[1]) === 1;
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder !== 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($input, '-_', '+/'), true);
        if ($decoded === false) {
            throw new JwtException('Invalid base64url segment', JwtException::MALFORMED);
        }

        return $decoded;
    }

    /** ECDSA: DER SEQUENCE(r, s) → fixed-width r||s as JWS requires. */
    private static function derToRaw(string $der, int $length): string
    {
        $half = intdiv($length, 2);
        $offset = 2; // SEQUENCE tag + length (short form is enough for P-256/384/521)
        if (ord($der[1]) & 0x80) {
            $offset += ord($der[1]) & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $offset++; // INTEGER tag
            $len = ord($der[$offset++]);
            $int = ltrim(substr($der, $offset, $len), "\x00");
            $offset += $len;
            $out .= str_pad($int, $half, "\x00", STR_PAD_LEFT);
        }

        return $out;
    }

    private static function rawToDer(string $raw): string
    {
        $half = intdiv(strlen($raw), 2);
        $encodeInt = static function (string $bytes): string {
            $bytes = ltrim($bytes, "\x00");
            if ($bytes === '' || ord($bytes[0]) & 0x80) {
                $bytes = "\x00" . $bytes;
            }

            return "\x02" . chr(strlen($bytes)) . $bytes;
        };
        $seq = $encodeInt(substr($raw, 0, $half)) . $encodeInt(substr($raw, $half));
        $len = strlen($seq);
        $lenBytes = $len < 0x80 ? chr($len) : "\x81" . chr($len);

        return "\x30" . $lenBytes . $seq;
    }
}
