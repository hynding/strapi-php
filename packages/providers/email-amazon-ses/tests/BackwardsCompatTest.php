<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailAmazonSes\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Provider\EmailAmazonSes\Utils;

/**
 * Port of src/__tests__/backwards-compat.vitest.test.ts: parity with `develop`
 * (@strapi/provider-email-amazon-ses + node-ses) configs and send shapes.
 */
final class BackwardsCompatTest extends TestCase
{
    /** Exact `develop` README providerOptions shape. */
    private const array DEVELOP_README_PROVIDER_OPTIONS = [
        'key' => 'AKIA_DEVELOP_KEY',
        'secret' => 'develop-secret-key',
        'amazon' => 'https://email.eu-west-1.amazonaws.com',
    ];

    private const array DEVELOP_SETTINGS = [
        'defaultFrom' => 'shipper@example.com',
        'defaultReplyTo' => 'reply@example.com',
    ];

    // providerOptions (develop config rewriting)

    public function testMapsTheDevelopReadmeExampleToAResolvableSesClientConfig(): void
    {
        self::assertEquals([
            'region' => 'eu-west-1',
            'endpoint' => 'https://email.eu-west-1.amazonaws.com',
            'credentials' => ['accessKeyId' => 'AKIA_DEVELOP_KEY', 'secretAccessKey' => 'develop-secret-key'],
        ], Utils::getClientConfig(self::DEVELOP_README_PROVIDER_OPTIONS));
    }

    public function testMapsKeyAndSecretOnlyLikeNodeSesDefaultAmazonHost(): void
    {
        self::assertEquals([
            'region' => 'us-east-1',
            'endpoint' => Utils::DEFAULT_SES_ENDPOINT,
            'credentials' => ['accessKeyId' => 'AKIA_DEVELOP_KEY', 'secretAccessKey' => 'develop-secret-key'],
        ], Utils::getClientConfig(['key' => 'AKIA_DEVELOP_KEY', 'secret' => 'develop-secret-key']));
    }

    public function testDoesNotPassUnknownProviderOptionsKeysThatNodeSesIgnored(): void
    {
        $config = Utils::getClientConfig(['key' => 'k', 'secret' => 's', 'amazon' => 'https://email.us-east-1.amazonaws.com', 'timeout' => 9999]);

        self::assertArrayNotHasKey('timeout', $config);
    }

    // send options (develop + node-ses)

    public function testMapsStrapiHtmlTextFieldsLikeDevelop(): void
    {
        $input = Utils::buildSendEmailCommandInput(['to' => 'user@example.com', 'subject' => 'Subject', 'text' => 'Plain', 'html' => '<b>Html</b>'], self::DEVELOP_SETTINGS);

        self::assertEquals([
            'Text' => ['Data' => 'Plain', 'Charset' => 'UTF-8'],
            'Html' => ['Data' => '<b>Html</b>', 'Charset' => 'UTF-8'],
        ], $input['Message']['Body']);
    }

    public function testPassesStringRecipientsAsASingleEntry(): void
    {
        self::assertSame(['a@example.com, b@example.com'], Utils::toAddressList('a@example.com, b@example.com'));
    }

    public function testPassesThroughRecipientArrays(): void
    {
        $input = Utils::buildSendEmailCommandInput(
            ['to' => ['one@example.com', 'two@example.com'], 'cc' => ['cc@example.com'], 'bcc' => ['bcc@example.com'], 'subject' => 'Subject', 'text' => 'Plain', 'html' => ''],
            self::DEVELOP_SETTINGS,
        );

        self::assertSame([
            'ToAddresses' => ['one@example.com', 'two@example.com'],
            'CcAddresses' => ['cc@example.com'],
            'BccAddresses' => ['bcc@example.com'],
        ], $input['Destination']);
    }

    public function testOmitsCcBccWhenUndefined(): void
    {
        $input = Utils::buildSendEmailCommandInput(['to' => 'user@example.com', 'subject' => 'Subject', 'text' => 'Plain', 'html' => ''], self::DEVELOP_SETTINGS);

        self::assertNull($input['Destination']['CcAddresses']);
        self::assertNull($input['Destination']['BccAddresses']);
    }

    public function testSupportsDefaultReplyToAsAnArray(): void
    {
        $input = Utils::buildSendEmailCommandInput(
            ['to' => 'user@example.com', 'subject' => 'Subject', 'text' => 'Plain', 'html' => ''],
            [...self::DEVELOP_SETTINGS, 'defaultReplyTo' => ['reply-a@example.com', 'reply-b@example.com']],
        );

        self::assertSame(['reply-a@example.com', 'reply-b@example.com'], $input['ReplyToAddresses']);
    }

    public function testSupportsReplyToAsAnArrayOnSend(): void
    {
        $input = Utils::buildSendEmailCommandInput(
            ['to' => 'user@example.com', 'replyTo' => ['reply-a@example.com', 'reply-b@example.com'], 'subject' => 'Subject', 'text' => 'Plain', 'html' => ''],
            self::DEVELOP_SETTINGS,
        );

        self::assertSame(['reply-a@example.com', 'reply-b@example.com'], $input['ReplyToAddresses']);
    }

    public function testMapsNodeSesConfigurationSetToConfigurationSetName(): void
    {
        $input = Utils::buildSendEmailCommandInput(
            ['to' => 'user@example.com', 'subject' => 'Subject', 'text' => 'Plain', 'html' => '', 'configurationSet' => 'my-config-set'],
            self::DEVELOP_SETTINGS,
        );

        self::assertSame('my-config-set', $input['ConfigurationSetName']);
        self::assertArrayNotHasKey('configurationSet', $input);
    }

    public function testMapsNodeSesMessageTagsToTags(): void
    {
        $input = Utils::buildSendEmailCommandInput(
            ['to' => 'user@example.com', 'subject' => 'Subject', 'text' => 'Plain', 'html' => '', 'messageTags' => [['name' => 'campaign', 'value' => 'welcome'], ['name' => 'segment', 'value' => 'beta']]],
            self::DEVELOP_SETTINGS,
        );

        self::assertSame([['Name' => 'campaign', 'Value' => 'welcome'], ['Name' => 'segment', 'Value' => 'beta']], $input['Tags']);
        self::assertArrayNotHasKey('messageTags', $input);
    }

    public function testSkipsEmptyHtmlLikeNodeSes(): void
    {
        $input = Utils::buildSendEmailCommandInput(['to' => 'user@example.com', 'subject' => 'Subject', 'text' => 'Plain only', 'html' => ''], self::DEVELOP_SETTINGS);

        self::assertSame(['Data' => 'Plain only', 'Charset' => 'UTF-8'], $input['Message']['Body']['Text']);
        self::assertArrayNotHasKey('Html', $input['Message']['Body']);
    }

    public function testSkipsEmptyTextLikeNodeSes(): void
    {
        $input = Utils::buildSendEmailCommandInput(['to' => 'user@example.com', 'subject' => 'Subject', 'text' => '', 'html' => '<p>Html only</p>'], self::DEVELOP_SETTINGS);

        self::assertSame(['Data' => '<p>Html only</p>', 'Charset' => 'UTF-8'], $input['Message']['Body']['Html']);
        self::assertArrayNotHasKey('Text', $input['Message']['Body']);
    }
}
