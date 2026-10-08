<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailAmazonSes\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Provider\EmailAmazonSes\Utils;

/**
 * Port of src/__tests__/utils.vitest.test.ts. Upstream also builds an SESClient from each config
 * and resolves its region; here the region resolution is checked in IndexTest.
 */
final class UtilsTest extends TestCase
{
    private const string ACCESS_KEY_ID = 'AKIA_LEGACY_KEY';

    private const string SECRET_ACCESS_KEY = 'legacy-secret-key';

    // regionFromEndpoint

    public function testParsesRegionFromTheLegacyReadmeAmazonUrl(): void
    {
        self::assertSame('eu-west-1', Utils::regionFromEndpoint('https://email.eu-west-1.amazonaws.com'));
    }

    public function testReturnsNullForEndpointsWithoutEmailRegionHost(): void
    {
        self::assertNull(Utils::regionFromEndpoint('http://localhost:4566'));
    }

    public function testParsesChinaPartitionHostnames(): void
    {
        self::assertSame('cn-north-1', Utils::regionFromEndpoint('https://email.cn-north-1.amazonaws.com.cn'));
    }

    // toAddressList

    public function testToAddressList(): void
    {
        self::assertSame(['a@example.com, b@example.com'], Utils::toAddressList('a@example.com, b@example.com'));
        self::assertSame(['one@example.com', 'two@example.com'], Utils::toAddressList(['one@example.com', 'two@example.com']));
        self::assertNull(Utils::toAddressList(''));
        self::assertNull(Utils::toAddressList(null));
    }

    // getClientConfig (legacy providerOptions rewrites)

    public function testRewritesThePublishedReadmeConfig(): void
    {
        self::assertEquals([
            'region' => 'us-east-1',
            'endpoint' => 'https://email.us-east-1.amazonaws.com',
            'credentials' => ['accessKeyId' => self::ACCESS_KEY_ID, 'secretAccessKey' => self::SECRET_ACCESS_KEY],
        ], Utils::getClientConfig(['key' => self::ACCESS_KEY_ID, 'secret' => self::SECRET_ACCESS_KEY, 'amazon' => 'https://email.us-east-1.amazonaws.com']));
    }

    public function testRewritesTopLevelKeyAndSecretOnly(): void
    {
        self::assertEquals([
            'region' => 'us-east-1',
            'endpoint' => Utils::DEFAULT_SES_ENDPOINT,
            'credentials' => ['accessKeyId' => self::ACCESS_KEY_ID, 'secretAccessKey' => self::SECRET_ACCESS_KEY],
        ], Utils::getClientConfig(['key' => self::ACCESS_KEY_ID, 'secret' => self::SECRET_ACCESS_KEY]));
    }

    public function testRewritesNestedCredentialsKeySecret(): void
    {
        self::assertEquals([
            'region' => 'ap-southeast-2',
            'credentials' => ['accessKeyId' => self::ACCESS_KEY_ID, 'secretAccessKey' => self::SECRET_ACCESS_KEY],
        ], Utils::getClientConfig(['region' => 'ap-southeast-2', 'credentials' => ['key' => self::ACCESS_KEY_ID, 'secret' => self::SECRET_ACCESS_KEY]]));
    }

    public function testPassesThroughStandardAwsCredentialsObject(): void
    {
        $credentials = ['accessKeyId' => self::ACCESS_KEY_ID, 'secretAccessKey' => self::SECRET_ACCESS_KEY];

        self::assertEquals(['region' => 'eu-central-1', 'credentials' => $credentials], Utils::getClientConfig(['region' => 'eu-central-1', 'credentials' => $credentials]));
    }

    public function testIncludesSessionTokenWhenProvidedInNestedCredentials(): void
    {
        $config = Utils::getClientConfig(['region' => 'us-east-1', 'credentials' => ['key' => self::ACCESS_KEY_ID, 'secret' => self::SECRET_ACCESS_KEY, 'sessionToken' => 'SESSION']]);

        self::assertSame(['accessKeyId' => self::ACCESS_KEY_ID, 'secretAccessKey' => self::SECRET_ACCESS_KEY, 'sessionToken' => 'SESSION'], $config['credentials']);
    }

    public function testSupportsIrsaStyleConfig(): void
    {
        self::assertSame(['region' => 'us-west-2'], Utils::getClientConfig(['region' => 'us-west-2']));
    }

    public function testPrefersExplicitRegionOverRegionParsedFromAmazon(): void
    {
        $config = Utils::getClientConfig(['key' => self::ACCESS_KEY_ID, 'secret' => self::SECRET_ACCESS_KEY, 'region' => 'ap-northeast-1', 'amazon' => 'https://email.eu-west-1.amazonaws.com']);

        self::assertSame('ap-northeast-1', $config['region']);
        self::assertSame('https://email.eu-west-1.amazonaws.com', $config['endpoint']);
    }

    public function testMapsAmazonAliasToEndpoint(): void
    {
        self::assertSame(Utils::DEFAULT_SES_ENDPOINT, Utils::getClientConfig(['key' => self::ACCESS_KEY_ID, 'secret' => self::SECRET_ACCESS_KEY, 'amazon' => Utils::DEFAULT_SES_ENDPOINT])['endpoint']);
    }

    public function testDoesNotInjectUsEast1WhenACustomEndpointHasNoParseableRegion(): void
    {
        $config = Utils::getClientConfig(['key' => self::ACCESS_KEY_ID, 'secret' => self::SECRET_ACCESS_KEY, 'endpoint' => 'http://localhost:4566']);

        self::assertArrayNotHasKey('region', $config);
        self::assertSame('http://localhost:4566', $config['endpoint']);
    }

    public function testDefaultsToUsEast1WhenLegacyAmazonUrlHasNoParseableRegion(): void
    {
        $config = Utils::getClientConfig(['key' => self::ACCESS_KEY_ID, 'secret' => self::SECRET_ACCESS_KEY, 'amazon' => 'https://invalid-url.com']);

        self::assertSame('us-east-1', $config['region']);
        self::assertSame('https://invalid-url.com', $config['endpoint']);
        self::assertSame(['accessKeyId' => self::ACCESS_KEY_ID, 'secretAccessKey' => self::SECRET_ACCESS_KEY], $config['credentials']);
    }

    // buildSendEmailCommandInput (legacy send field mapping)

    private const array SETTINGS = ['defaultFrom' => 'noreply@example.com', 'defaultReplyTo' => 'support@example.com'];

    public function testMapsHtmlToHtmlAndTextToText(): void
    {
        $input = Utils::buildSendEmailCommandInput(['to' => 'recipient@example.com', 'subject' => 'Hello', 'text' => 'Plain body', 'html' => '<p>Html body</p>'], self::SETTINGS);

        self::assertEquals([
            'Html' => ['Data' => '<p>Html body</p>', 'Charset' => 'UTF-8'],
            'Text' => ['Data' => 'Plain body', 'Charset' => 'UTF-8'],
        ], $input['Message']['Body']);
    }

    public function testUsesSettingsDefaultFromAndDefaultReplyToWhenOmitted(): void
    {
        $input = Utils::buildSendEmailCommandInput(['to' => 'recipient@example.com', 'subject' => 'Test', 'text' => 'Body', 'html' => ''], self::SETTINGS);

        self::assertSame('noreply@example.com', $input['Source']);
        self::assertSame(['support@example.com'], $input['ReplyToAddresses']);
    }

    public function testSupportsArrayDefaultReplyTo(): void
    {
        $input = Utils::buildSendEmailCommandInput(
            ['to' => 'recipient@example.com', 'subject' => 'Test', 'text' => 'Body', 'html' => ''],
            [...self::SETTINGS, 'defaultReplyTo' => ['support@example.com', 'billing@example.com']],
        );

        self::assertSame(['support@example.com', 'billing@example.com'], $input['ReplyToAddresses']);
    }

    public function testMapsStrapiStyleCcBccStringsToDestinationLists(): void
    {
        $input = Utils::buildSendEmailCommandInput(
            ['to' => 'to@example.com', 'cc' => 'cc@example.com', 'bcc' => 'bcc1@example.com, bcc2@example.com', 'subject' => 'Test', 'text' => 'Body', 'html' => ''],
            self::SETTINGS,
        );

        self::assertSame([
            'ToAddresses' => ['to@example.com'],
            'CcAddresses' => ['cc@example.com'],
            'BccAddresses' => ['bcc1@example.com, bcc2@example.com'],
        ], $input['Destination']);
    }
}
