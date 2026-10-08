<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailAmazonSes\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Provider\EmailAmazonSes\SignatureV4;

/** Not an upstream test: AWS Signature V4 against the AWS SigV4 test suite (`get-vanilla`). */
final class SignatureV4Test extends TestCase
{
    public function testGetVanilla(): void
    {
        $headers = SignatureV4::sign(
            'GET',
            'https://example.amazonaws.com/',
            [],
            '',
            'service',
            'us-east-1',
            ['accessKeyId' => 'AKIDEXAMPLE', 'secretAccessKey' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY'],
            new \DateTimeImmutable('2015-08-30T12:36:00Z'),
        );

        self::assertSame('20150830T123600Z', $headers['x-amz-date']);
        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $headers['authorization'],
        );
    }
}
