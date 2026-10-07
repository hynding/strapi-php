<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Utils\Jwt;
use Strapi\Core\Utils\JwtException;

final class JwtTest extends TestCase
{
    /** Produced by Node `jsonwebtoken@9`: jwt.sign(payload, 'tobemodified', { algorithm: 'HS256' }). */
    private const NODE_TOKEN = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VySWQiOiIxIiwic2Vzc2lvbklkIjoiYWJjIiwidHlwZSI6InJlZnJlc2giLCJpYXQiOjE3MDAwMDAwMDAsImV4cCI6MTcwMDAwMzYwMH0.zympqKydfwn_4R3xQlT9Ri4vfLFJOljC4ndgRRu1Tyo';

    private const PAYLOAD = ['userId' => '1', 'sessionId' => 'abc', 'type' => 'refresh', 'iat' => 1700000000, 'exp' => 1700003600];

    public function testEncodeMatchesNodeJsonwebtokenByteForByte(): void
    {
        // Strapi's default .env secret is 12 bytes; no minimum key length is enforced, like jsonwebtoken.
        self::assertSame(self::NODE_TOKEN, Jwt::encode(self::PAYLOAD, 'tobemodified'));
    }

    public function testDecodeVerifiesTokensIssuedByNodeStrapi(): void
    {
        self::assertSame(self::PAYLOAD, Jwt::decode(self::NODE_TOKEN, 'tobemodified', 'HS256', 1700000100));
    }

    public function testExpiredToken(): void
    {
        $this->expectException(JwtException::class);
        $this->expectExceptionCode(JwtException::EXPIRED);
        Jwt::decode(self::NODE_TOKEN, 'tobemodified', 'HS256', 1700003600);
    }

    public function testNotBefore(): void
    {
        $token = Jwt::encode(['nbf' => 1700000000], 'secret');
        $this->expectExceptionCode(JwtException::BEFORE_VALID);
        Jwt::decode($token, 'secret', 'HS256', 1699999999);
    }

    public function testWrongSecret(): void
    {
        $this->expectExceptionCode(JwtException::INVALID_SIGNATURE);
        Jwt::decode(self::NODE_TOKEN, 'other', 'HS256', 1700000100);
    }

    public function testAlgorithmMismatchIsRejected(): void
    {
        $this->expectExceptionCode(JwtException::MALFORMED);
        Jwt::decode(self::NODE_TOKEN, 'tobemodified', 'HS512', 1700000100);
    }

    public function testMalformedToken(): void
    {
        $this->expectExceptionCode(JwtException::MALFORMED);
        Jwt::decode('a.b', 'tobemodified');
    }

    public function testUnsupportedAlgorithm(): void
    {
        $this->expectException(\DomainException::class);
        Jwt::encode([], 'secret', 'none');
    }

    public function testRs256RoundTrip(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $private);
        $public = openssl_pkey_get_details($key)['key'];

        $token = Jwt::encode(['sub' => 'x', 'exp' => time() + 60], $private, 'RS256');
        self::assertSame('x', Jwt::decode($token, $public, 'RS256')['sub']);
        $this->expectExceptionCode(JwtException::INVALID_SIGNATURE);
        Jwt::decode($token . 'x', $public, 'RS256');
    }

    public function testEs256RoundTrip(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $private);
        $public = openssl_pkey_get_details($key)['key'];

        $token = Jwt::encode(['sub' => 'y'], $private, 'ES256');
        [, , $signature] = explode('.', $token);
        self::assertSame(64, strlen(Jwt::base64UrlDecode($signature)), 'JWS ES256 signatures are raw r||s, 64 bytes');
        self::assertSame('y', Jwt::decode($token, $public, 'ES256')['sub']);
    }
}
