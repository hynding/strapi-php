<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Utils;

use Strapi\Core\Utils\Jwt;

/**
 * Port of server/src/utils/verify-jwt-with-jwks.js. `crypto.createPublicKey({ format: 'jwk' })`
 * becomes an OpenSSL key built from the JWK's modulus and exponent (RSA keys, which is what an
 * RS256 JWKS holds).
 */
final class VerifyJwtWithJwks
{
    /**
     * @param array<string, mixed> $jwk
     */
    public static function jwkToKeyObject(array $jwk): \OpenSSLAsymmetricKey
    {
        if (($jwk['kty'] ?? null) !== 'RSA' || !is_string($jwk['n'] ?? null) || !is_string($jwk['e'] ?? null)) {
            throw new \InvalidArgumentException('Invalid JWK');
        }

        $modulus = Jwt::base64UrlDecode($jwk['n']);
        $exponent = Jwt::base64UrlDecode($jwk['e']);

        $rsaPublicKey = self::derSequence(self::derInteger($modulus) . self::derInteger($exponent));
        // AlgorithmIdentifier rsaEncryption (1.2.840.113549.1.1.1) + NULL
        $algorithm = self::derSequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $spki = self::derSequence($algorithm . "\x03" . self::derLength(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey);

        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";

        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new \InvalidArgumentException('Invalid JWK');
        }

        return $key;
    }

    /** The PEM of a key built by {@see self::jwkToKeyObject()}. */
    public static function keyToPem(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);

        return is_array($details) && is_string($details['key'] ?? null) ? $details['key'] : '';
    }

    /**
     * @return array<string, mixed>
     */
    public static function verifyJwtWithJwks(string $idToken, string $jwksUrl): array
    {
        $parts = explode('.', $idToken);
        $header = count($parts) === 3 ? json_decode(Jwt::base64UrlDecode($parts[0]), true) : null;
        $payload = count($parts) === 3 ? json_decode(Jwt::base64UrlDecode($parts[1]), true) : null;

        if (!is_array($header) || !isset($header['kid']) || $header['kid'] === '' || !is_array($payload)) {
            throw new \RuntimeException('The provided token is not valid');
        }

        $response = ProviderHttp::fetch($jwksUrl);
        if (!$response['ok']) {
            throw new \RuntimeException('There was an error verifying the token');
        }

        $jwk = ProviderHttp::json($response);
        $key = null;
        foreach (is_array($jwk) && is_array($jwk['keys'] ?? null) ? $jwk['keys'] : [] as $candidate) {
            if (is_array($candidate) && ($candidate['kid'] ?? null) === $header['kid']) {
                $key = $candidate;
                break;
            }
        }

        if ($key === null) {
            throw new \RuntimeException('There was an error verifying the token');
        }

        try {
            $publicKey = self::keyToPem(self::jwkToKeyObject($key));

            return Jwt::decode($idToken, $publicKey, 'RS256');
        } catch (\Throwable) {
            throw new \RuntimeException('There was an error verifying the token');
        }
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derSequence(string $content): string
    {
        return "\x30" . self::derLength(strlen($content)) . $content;
    }
}
