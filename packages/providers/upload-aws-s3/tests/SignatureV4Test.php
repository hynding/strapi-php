<?php

declare(strict_types=1);

namespace Strapi\Provider\UploadAwsS3\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Provider\UploadAwsS3\SignatureV4;

/**
 * PHP-port addition: the signer against AWS's published examples.
 *
 * - S3 "Signature Calculations for the Authorization Header" examples (GET Object with a Range
 *   header, PUT Object) and the "Query String" presigned GET example — Amazon S3 API Reference,
 *   sigv4-header-based-auth / sigv4-query-string-auth, bucket `examplebucket`, 2013-05-24.
 * - `get-vanilla` from the AWS Signature Version 4 test suite (service `service`, 2015-08-30).
 */
final class SignatureV4Test extends TestCase
{
    private const array S3_CREDENTIALS = ['accessKeyId' => 'AKIAIOSFODNN7EXAMPLE', 'secretAccessKey' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'];

    private const string EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private static function date(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2013-05-24T00:00:00Z');
    }

    public function testS3GetObjectExample(): void
    {
        $headers = (new SignatureV4('s3', 'us-east-1'))->sign('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', [
            'Host' => 'examplebucket.s3.amazonaws.com',
            'Range' => 'bytes=0-9',
            'x-amz-content-sha256' => self::EMPTY_SHA256,
        ], self::EMPTY_SHA256, self::S3_CREDENTIALS, self::date());

        self::assertSame('20130524T000000Z', $headers['x-amz-date']);
        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, '
            . 'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, '
            . 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $headers['authorization'],
        );
    }

    public function testS3PutObjectExample(): void
    {
        $payloadHash = hash('sha256', 'Welcome to Amazon S3.');
        self::assertSame('44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072', $payloadHash);

        $headers = (new SignatureV4('s3', 'us-east-1'))->sign('PUT', 'https://examplebucket.s3.amazonaws.com/test%24file.text', [
            'Host' => 'examplebucket.s3.amazonaws.com',
            'Date' => 'Fri, 24 May 2013 00:00:00 GMT',
            'x-amz-storage-class' => 'REDUCED_REDUNDANCY',
            'x-amz-content-sha256' => $payloadHash,
        ], $payloadHash, self::S3_CREDENTIALS, self::date());

        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, '
            . 'SignedHeaders=date;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class, '
            . 'Signature=98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd',
            $headers['authorization'],
        );
    }

    public function testS3PresignedUrlExample(): void
    {
        $url = (new SignatureV4('s3', 'us-east-1'))->presign('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', self::S3_CREDENTIALS, 86400, self::date());

        self::assertSame(
            'https://examplebucket.s3.amazonaws.com/test.txt'
            . '?X-Amz-Algorithm=AWS4-HMAC-SHA256'
            . '&X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request'
            . '&X-Amz-Date=20130524T000000Z&X-Amz-Expires=86400&X-Amz-SignedHeaders=host'
            . '&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404',
            $url,
        );
    }

    public function testSigV4TestSuiteGetVanilla(): void
    {
        $signer = new SignatureV4('service', 'us-east-1');
        $date = new \DateTimeImmutable('2015-08-30T12:36:00Z');

        [$canonicalHeaders, $signedHeaders] = SignatureV4::canonicalHeaders(['host' => 'example.amazonaws.com', 'x-amz-date' => '20150830T123600Z']);
        $canonicalRequest = SignatureV4::canonicalRequest('GET', 'https://example.amazonaws.com/', $canonicalHeaders, $signedHeaders, self::EMPTY_SHA256);
        self::assertSame("GET\n/\n\nhost:example.amazonaws.com\nx-amz-date:20150830T123600Z\n\nhost;x-amz-date\n" . self::EMPTY_SHA256, $canonicalRequest);
        self::assertSame(
            "AWS4-HMAC-SHA256\n20150830T123600Z\n20150830/us-east-1/service/aws4_request\nbb579772317eb040ac9ed261061d46c1f17a8133879d6129b6e1c25292927e63",
            $signer->stringToSign($canonicalRequest, '20150830T123600Z', '20150830/us-east-1/service/aws4_request'),
        );

        $headers = $signer->sign('GET', 'https://example.amazonaws.com/', ['host' => 'example.amazonaws.com'], self::EMPTY_SHA256, [
            'accessKeyId' => 'AKIDEXAMPLE',
            'secretAccessKey' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
        ], $date);

        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, '
            . 'SignedHeaders=host;x-amz-date, '
            . 'Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $headers['authorization'],
        );
    }

    public function testCanonicalQueryIsSortedAndEncodedOnce(): void
    {
        self::assertSame('a=1&b=x%20y&uploadId=abc%2Fdef', SignatureV4::canonicalQuery('uploadId=abc%2Fdef&b=x%20y&a=1'));
        self::assertSame('uploads=', SignatureV4::canonicalQuery('uploads'));
    }

    public function testSessionTokenIsSignedAndPresignRejectsMoreThanAWeek(): void
    {
        $signer = new SignatureV4('s3', 'us-east-1');
        $headers = $signer->sign('GET', 'https://b.s3.amazonaws.com/k', ['host' => 'b.s3.amazonaws.com'], self::EMPTY_SHA256, [...self::S3_CREDENTIALS, 'sessionToken' => 'TOKEN'], self::date());
        self::assertSame('TOKEN', $headers['x-amz-security-token']);
        self::assertStringContainsString('SignedHeaders=host;x-amz-date;x-amz-security-token,', $headers['authorization']);

        $this->expectExceptionMessage('less than one week');
        $signer->presign('GET', 'https://b.s3.amazonaws.com/k', self::S3_CREDENTIALS, 604801, self::date());
    }
}
