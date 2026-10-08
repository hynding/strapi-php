<?php

declare(strict_types=1);

namespace Strapi\Provider\UploadAwsS3\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Provider\UploadAwsS3\Utils;

/** Port of src/__tests__/utils.vitest.test.ts. */
final class UtilsTest extends TestCase
{
    private const string ACCESS_KEY_ID = 'AWS_ACCESS_KEY_ID';

    private const string SECRET_ACCESS_KEY = 'AWS_ACCESS_SECRET';

    private const array DEFAULT_OPTIONS = [
        'region' => 'AWS_REGION',
        'params' => [
            'ACL' => 'public-read',
            'signedUrlExpires' => 111111111111,
            'Bucket' => 'AWS_BUCKET',
        ],
    ];

    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->warnings = [];
        Utils::$warningHandler = function (string $message): void {
            $this->warnings[] = $message;
        };
    }

    protected function tearDown(): void
    {
        Utils::$warningHandler = null;
    }

    public function testCredentialsInCredentialsObjectInsideS3Options(): void
    {
        $credentials = Utils::extractCredentials(['s3Options' => [
            'credentials' => ['accessKeyId' => self::ACCESS_KEY_ID, 'secretAccessKey' => self::SECRET_ACCESS_KEY],
            ...self::DEFAULT_OPTIONS,
        ]]);

        self::assertSame(['accessKeyId' => self::ACCESS_KEY_ID, 'secretAccessKey' => self::SECRET_ACCESS_KEY], $credentials);
    }

    public function testCredentialsWithStsSessionTokenInsideS3Options(): void
    {
        $credentials = Utils::extractCredentials(['s3Options' => [
            'credentials' => ['accessKeyId' => self::ACCESS_KEY_ID, 'secretAccessKey' => self::SECRET_ACCESS_KEY, 'sessionToken' => 'AWS_SESSION_TOKEN'],
            ...self::DEFAULT_OPTIONS,
        ]]);

        self::assertSame([
            'accessKeyId' => self::ACCESS_KEY_ID,
            'secretAccessKey' => self::SECRET_ACCESS_KEY,
            'sessionToken' => 'AWS_SESSION_TOKEN',
        ], $credentials);
    }

    public function testCredentialsWithoutSessionTokenShouldNotIncludeSessionToken(): void
    {
        $credentials = Utils::extractCredentials(['s3Options' => [
            'credentials' => ['accessKeyId' => self::ACCESS_KEY_ID, 'secretAccessKey' => self::SECRET_ACCESS_KEY],
            ...self::DEFAULT_OPTIONS,
        ]]);

        self::assertIsArray($credentials);
        self::assertArrayNotHasKey('sessionToken', $credentials);
    }

    public function testDoesNotThrowAnErrorWhenCredentialsAreNotPresent(): void
    {
        self::assertNull(Utils::extractCredentials(['s3Options' => self::DEFAULT_OPTIONS]));
    }

    public function testExtractsRootLevelAccessKeyIdSecretAccessKeyFromS3Options(): void
    {
        $credentials = Utils::extractCredentials(['s3Options' => [
            'accessKeyId' => self::ACCESS_KEY_ID,
            'secretAccessKey' => self::SECRET_ACCESS_KEY,
            ...self::DEFAULT_OPTIONS,
        ]]);

        self::assertSame(['accessKeyId' => self::ACCESS_KEY_ID, 'secretAccessKey' => self::SECRET_ACCESS_KEY], $credentials);
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('deprecated', $this->warnings[0]);
    }

    public function testPrefersCredentialsObjectOverRootLevelKeys(): void
    {
        $credentials = Utils::extractCredentials(['s3Options' => [
            'accessKeyId' => 'ROOT_KEY',
            'secretAccessKey' => 'ROOT_SECRET',
            'credentials' => ['accessKeyId' => self::ACCESS_KEY_ID, 'secretAccessKey' => self::SECRET_ACCESS_KEY],
            ...self::DEFAULT_OPTIONS,
        ]]);

        self::assertSame(['accessKeyId' => self::ACCESS_KEY_ID, 'secretAccessKey' => self::SECRET_ACCESS_KEY], $credentials);
    }

    public function testReturnsNullWhenOnlyAccessKeyIdIsPresentWithoutSecretAccessKey(): void
    {
        self::assertNull(Utils::extractCredentials(['s3Options' => ['accessKeyId' => self::ACCESS_KEY_ID, ...self::DEFAULT_OPTIONS]]));
    }

    public function testPassesACredentialProviderFunctionThroughUnchanged(): void
    {
        $provider = static fn (): array => ['accessKeyId' => self::ACCESS_KEY_ID, 'secretAccessKey' => self::SECRET_ACCESS_KEY];

        // The provider must be returned as-is so the client can resolve and refresh credentials at runtime.
        self::assertSame($provider, Utils::extractCredentials(['s3Options' => ['credentials' => $provider, ...self::DEFAULT_OPTIONS]]));
    }
}
