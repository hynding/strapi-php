<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailAmazonSes\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Provider\EmailAmazonSes\EmailAmazonSes as Provider;
use Strapi\Provider\EmailAmazonSes\SesClient;
use Strapi\Provider\EmailAmazonSes\SesServiceException;

/**
 * Port of src/__tests__/index.vitest.test.ts. Upstream mocks `@aws-sdk/client-ses` and checks the
 * SESClient config and the SendEmailCommand input; here the client config is read from the
 * instance and the input from the SES Query request sent through a mocked `fetch`.
 */
final class IndexTest extends TestCase
{
    /** @var list<array{url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @var array{ok: bool, status: int, headers: array<string, string>, body: string} */
    private array $response = [
        'ok' => true,
        'status' => 200,
        'headers' => [],
        'body' => '<SendEmailResponse xmlns="http://ses.amazonaws.com/doc/2010-12-01/"><SendEmailResult><MessageId>0100-abc</MessageId></SendEmailResult><ResponseMetadata><RequestId>req-1</RequestId></ResponseMetadata></SendEmailResponse>',
    ];

    private const array SETTINGS = [
        'defaultFrom' => 'default@example.com',
        'defaultReplyTo' => 'default-reply@example.com',
    ];

    private const array LEGACY_CREDENTIALS = ['key' => 'test-access-key', 'secret' => 'test-secret-key'];

    private const array EXPECTED_LEGACY_CREDENTIALS = ['accessKeyId' => 'test-access-key', 'secretAccessKey' => 'test-secret-key'];

    /** @param array<string, mixed> $providerOptions */
    private function init(array $providerOptions = ['region' => 'us-east-1', 'credentials' => ['accessKeyId' => 'AKID', 'secretAccessKey' => 'SECRET']]): Provider
    {
        $requests = &$this->requests;
        $test = $this;

        return Provider::init($providerOptions, self::SETTINGS, null, static function (string $url, array $options) use (&$requests, $test): array {
            $requests[] = ['url' => $url, 'options' => $options];

            return $test->response();
        });
    }

    /** @return array{ok: bool, status: int, headers: array<string, string>, body: string} */
    public function response(): array
    {
        return $this->response;
    }

    /** @return array<string, string> the SES Query parameters of the last request */
    private function lastParams(): array
    {
        self::assertNotEmpty($this->requests);
        $params = [];
        foreach (explode('&', (string) $this->requests[count($this->requests) - 1]['options']['body']) as $pair) {
            [$k, $v] = explode('=', $pair, 2);
            $params[rawurldecode($k)] = rawurldecode($v);
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private static function expected(array $input): array
    {
        return ['Action' => 'SendEmail', 'Version' => '2010-12-01', ...SesClient::serialize($input)];
    }

    /** @param array<string, mixed> $overrides */
    private static function baseEmailOptions(array $overrides = []): array
    {
        return [
            'to' => 'recipient@example.com',
            'subject' => 'Test Subject',
            'text' => 'Plain text content',
            'html' => '<p>HTML content</p>',
            ...$overrides,
        ];
    }

    // init — legacy options

    public function testCreatesSesClientWithCredentialsFromKeySecret(): void
    {
        $config = $this->init(self::LEGACY_CREDENTIALS)->client->config;

        self::assertSame('us-east-1', $config['region']);
        self::assertSame('https://email.us-east-1.amazonaws.com', $config['endpoint']);
        self::assertSame(self::EXPECTED_LEGACY_CREDENTIALS, $config['credentials']);
    }

    public function testExtractsRegionFromAmazonUrl(): void
    {
        $instance = $this->init([...self::LEGACY_CREDENTIALS, 'amazon' => 'https://email.eu-west-1.amazonaws.com']);

        self::assertSame('eu-west-1', $instance->client->config['region']);
        self::assertSame('https://email.eu-west-1.amazonaws.com', $instance->client->config['endpoint']);

        $instance->send(self::baseEmailOptions());
        self::assertSame('https://email.eu-west-1.amazonaws.com', $this->requests[0]['url']);
        self::assertStringContainsString('Credential=test-access-key/', $this->requests[0]['options']['headers']['authorization']);
        self::assertMatchesRegularExpression('#/eu-west-1/ses/aws4_request#', $this->requests[0]['options']['headers']['authorization']);
    }

    public function testDefaultsToUsEast1IfAmazonUrlIsInvalid(): void
    {
        $config = $this->init([...self::LEGACY_CREDENTIALS, 'amazon' => 'https://invalid-url.com'])->client->config;

        self::assertSame('us-east-1', $config['region']);
        self::assertSame('https://invalid-url.com', $config['endpoint']);
        self::assertSame(self::EXPECTED_LEGACY_CREDENTIALS, $config['credentials']);
    }

    // init — SDK options

    public function testCreatesSesClientWithRegionOnly(): void
    {
        self::assertSame(['region' => 'ap-southeast-1'], $this->init(['region' => 'ap-southeast-1'])->client->config);
    }

    public function testCreatesSesClientWithExplicitCredentials(): void
    {
        $providerOptions = ['region' => 'us-west-2', 'credentials' => ['accessKeyId' => 'explicit-key', 'secretAccessKey' => 'explicit-secret']];

        self::assertSame($providerOptions, $this->init($providerOptions)->client->config);
    }

    // send

    public function testSendsEmailWithAllFields(): void
    {
        $this->init()->send([
            'from' => 'sender@example.com',
            'to' => 'recipient@example.com',
            'cc' => 'cc@example.com',
            'bcc' => 'bcc@example.com',
            'replyTo' => 'reply@example.com',
            'subject' => 'Test Subject',
            'text' => 'Plain text content',
            'html' => '<p>HTML content</p>',
        ]);

        self::assertCount(1, $this->requests);
        self::assertSame('https://email.us-east-1.amazonaws.com', $this->requests[0]['url']);
        self::assertSame('POST', $this->requests[0]['options']['method']);
        self::assertEquals(self::expected([
            'Source' => 'sender@example.com',
            'Destination' => [
                'ToAddresses' => ['recipient@example.com'],
                'CcAddresses' => ['cc@example.com'],
                'BccAddresses' => ['bcc@example.com'],
            ],
            'Message' => [
                'Subject' => ['Data' => 'Test Subject', 'Charset' => 'UTF-8'],
                'Body' => [
                    'Text' => ['Data' => 'Plain text content', 'Charset' => 'UTF-8'],
                    'Html' => ['Data' => '<p>HTML content</p>', 'Charset' => 'UTF-8'],
                ],
            ],
            'ReplyToAddresses' => ['reply@example.com'],
        ]), $this->lastParams());
    }

    public function testUsesDefaultFromAndReplyToFromSettings(): void
    {
        $this->init()->send(self::baseEmailOptions());

        self::assertSame('default@example.com', $this->lastParams()['Source']);
        self::assertSame('default-reply@example.com', $this->lastParams()['ReplyToAddresses.member.1']);
    }

    public function testHandlesArrayOfRecipients(): void
    {
        $this->init()->send(self::baseEmailOptions([
            'to' => ['recipient1@example.com', 'recipient2@example.com'],
            'cc' => ['cc1@example.com', 'cc2@example.com'],
            'bcc' => ['bcc1@example.com', 'bcc2@example.com'],
        ]));
        $params = $this->lastParams();

        self::assertSame('recipient2@example.com', $params['Destination.ToAddresses.member.2']);
        self::assertSame('cc2@example.com', $params['Destination.CcAddresses.member.2']);
        self::assertSame('bcc2@example.com', $params['Destination.BccAddresses.member.2']);
    }

    public function testHandlesUndefinedCcAndBcc(): void
    {
        $this->init()->send(self::baseEmailOptions());
        $destination = array_filter($this->lastParams(), static fn (string $k): bool => str_starts_with($k, 'Destination.'), ARRAY_FILTER_USE_KEY);

        self::assertSame(['Destination.ToAddresses.member.1' => 'recipient@example.com'], $destination);
    }

    public function testHandlesTextOnlyEmail(): void
    {
        $this->init()->send(self::baseEmailOptions(['html' => '']));
        $params = $this->lastParams();

        self::assertSame('Plain text content', $params['Message.Body.Text.Data']);
        self::assertArrayNotHasKey('Message.Body.Html.Data', $params);
    }

    public function testHandlesHtmlOnlyEmail(): void
    {
        $this->init()->send(self::baseEmailOptions(['text' => '']));
        $params = $this->lastParams();

        self::assertSame('<p>HTML content</p>', $params['Message.Body.Html.Data']);
        self::assertArrayNotHasKey('Message.Body.Text.Data', $params);
    }

    public function testThrowsWhenSesFails(): void
    {
        $this->response = [
            'ok' => false,
            'status' => 400,
            'headers' => [],
            'body' => '<ErrorResponse xmlns="http://ses.amazonaws.com/doc/2010-12-01/"><Error><Type>Sender</Type><Code>MessageRejected</Code><Message>Email address not verified</Message></Error><RequestId>r</RequestId></ErrorResponse>',
        ];

        try {
            $this->init()->send(self::baseEmailOptions());
            self::fail('expected an error');
        } catch (SesServiceException $e) {
            self::assertSame('Email address not verified', $e->getMessage());
            self::assertSame('MessageRejected', $e->name);
            self::assertSame(400, $e->metadata['httpStatusCode']);
        }
    }

    public function testMapsNodeSesConfigurationSetOnSend(): void
    {
        $this->init()->send([...self::baseEmailOptions(), 'configurationSet' => 'tracked-set']);

        self::assertSame('tracked-set', $this->lastParams()['ConfigurationSetName']);
        self::assertArrayNotHasKey('configurationSet', $this->lastParams());
    }

    public function testMapsNodeSesMessageTagsOnSend(): void
    {
        $this->init()->send([...self::baseEmailOptions(), 'messageTags' => [['name' => 'env', 'value' => 'staging']]]);

        self::assertSame('env', $this->lastParams()['Tags.member.1.Name']);
        self::assertSame('staging', $this->lastParams()['Tags.member.1.Value']);
    }

    public function testUsesTemporaryCredentialsFromTheEnvironment(): void
    {
        putenv('AWS_ACCESS_KEY_ID=ENVKEY');
        putenv('AWS_SECRET_ACCESS_KEY=ENVSECRET');
        putenv('AWS_SESSION_TOKEN=ENVTOKEN');
        try {
            $this->init(['region' => 'us-west-2'])->send(self::baseEmailOptions());
        } finally {
            putenv('AWS_ACCESS_KEY_ID');
            putenv('AWS_SECRET_ACCESS_KEY');
            putenv('AWS_SESSION_TOKEN');
        }

        $headers = $this->requests[0]['options']['headers'];
        self::assertSame('https://email.us-west-2.amazonaws.com', $this->requests[0]['url']);
        self::assertSame('ENVTOKEN', $headers['x-amz-security-token']);
        self::assertStringContainsString('Credential=ENVKEY/', $headers['authorization']);
        self::assertStringContainsString('SignedHeaders=content-type;host;x-amz-date;x-amz-security-token', $headers['authorization']);
    }
}
