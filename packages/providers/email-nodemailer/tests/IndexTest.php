<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailNodemailer\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Provider\EmailNodemailer\EmailNodemailer as Provider;
use Strapi\Provider\EmailNodemailer\Nodemailer\Nodemailer;
use Strapi\Provider\EmailNodemailer\Nodemailer\Transporter;

/** Port of src/__tests__/index.vitest.test.ts (`vi.mock('nodemailer')` → Nodemailer::$createTransport). */
final class IndexTest extends TestCase
{
    private MockTransporter $transporter;

    /** @var list<array<string, mixed>> */
    private array $createTransportCalls = [];

    private const array SETTINGS = [
        'defaultFrom' => 'noreply@example.com',
        'defaultReplyTo' => 'support@example.com',
    ];

    private const array PROVIDER_OPTIONS = [
        'host' => 'smtp.example.com',
        'port' => 587,
    ];

    protected function setUp(): void
    {
        $this->transporter = new MockTransporter();
        $calls = &$this->createTransportCalls;
        $transporter = $this->transporter;
        Nodemailer::$createTransport = static function (array $options) use (&$calls, $transporter): Transporter {
            $calls[] = $options;

            return $transporter;
        };
    }

    protected function tearDown(): void
    {
        Nodemailer::$createTransport = null;
    }

    /** @return array<string, mixed> */
    private function sent(): array
    {
        self::assertNotEmpty($this->transporter->sent);

        return $this->transporter->sent[0];
    }

    // init

    public function testCreatesATransporterWithProviderOptions(): void
    {
        $providerOptions = [
            'host' => 'smtp.example.com',
            'port' => 587,
            'auth' => ['user' => 'test@example.com', 'pass' => 'password'],
        ];

        Provider::init($providerOptions, self::SETTINGS);

        self::assertSame([$providerOptions], $this->createTransportCalls);
    }

    // send

    public function testSendsAnEmailWithTheProvidedOptions(): void
    {
        $instance = Provider::init(self::PROVIDER_OPTIONS, self::SETTINGS);

        $instance->send([
            'to' => 'recipient@example.com',
            'subject' => 'Test Subject',
            'text' => 'Test content',
            'html' => '<p>Test content</p>',
        ]);

        self::assertSame([
            'from' => 'noreply@example.com',
            'to' => 'recipient@example.com',
            'cc' => null,
            'bcc' => null,
            'replyTo' => 'support@example.com',
            'subject' => 'Test Subject',
            'text' => 'Test content',
            'html' => '<p>Test content</p>',
            'disableFileAccess' => true,
            'disableUrlAccess' => true,
        ], $this->sent());
    }

    public function testUsesProvidedFromAndReplyToOverDefaults(): void
    {
        Provider::init(self::PROVIDER_OPTIONS, self::SETTINGS)->send([
            'from' => 'custom@example.com',
            'to' => 'recipient@example.com',
            'replyTo' => 'custom-reply@example.com',
            'subject' => 'Test Subject',
            'text' => 'Test content',
        ]);

        self::assertSame('custom@example.com', $this->sent()['from']);
        self::assertSame('custom-reply@example.com', $this->sent()['replyTo']);
    }

    public function testFallsBackToHtmlWhenTextIsNotProvided(): void
    {
        Provider::init(self::PROVIDER_OPTIONS, self::SETTINGS)->send([
            'to' => 'recipient@example.com',
            'subject' => 'Test Subject',
            'html' => '<p>HTML content</p>',
        ]);

        self::assertSame('<p>HTML content</p>', $this->sent()['text']);
        self::assertSame('<p>HTML content</p>', $this->sent()['html']);
    }

    public function testFallsBackToTextWhenHtmlIsNotProvided(): void
    {
        Provider::init(self::PROVIDER_OPTIONS, self::SETTINGS)->send([
            'to' => 'recipient@example.com',
            'subject' => 'Test Subject',
            'text' => 'Plain text content',
        ]);

        self::assertSame('Plain text content', $this->sent()['text']);
        self::assertSame('Plain text content', $this->sent()['html']);
    }

    public function testRespectsEmptyStringForTextWithoutFallingBackToHtml(): void
    {
        Provider::init(self::PROVIDER_OPTIONS, self::SETTINGS)->send([
            'to' => 'recipient@example.com',
            'subject' => 'Test Subject',
            'text' => '',
            'html' => '<p>HTML only</p>',
        ]);

        self::assertSame('', $this->sent()['text']);
        self::assertSame('<p>HTML only</p>', $this->sent()['html']);
    }

    public function testRespectsEmptyStringForHtmlWithoutFallingBackToText(): void
    {
        Provider::init(self::PROVIDER_OPTIONS, self::SETTINGS)->send([
            'to' => 'recipient@example.com',
            'subject' => 'Test Subject',
            'text' => 'Plain text',
            'html' => '',
        ]);

        self::assertSame('Plain text', $this->sent()['text']);
        self::assertSame('', $this->sent()['html']);
    }

    public function testPassesPriorityAndHeaders(): void
    {
        Provider::init(self::PROVIDER_OPTIONS, self::SETTINGS)->send([
            'to' => 'recipient@example.com',
            'subject' => 'Test Subject',
            'text' => 'Test content',
            'priority' => 'high',
            'headers' => ['X-Custom-Header' => 'value'],
        ]);

        self::assertSame('high', $this->sent()['priority']);
        self::assertSame(['X-Custom-Header' => 'value'], $this->sent()['headers']);
    }

    public function testPassesDsnConfiguration(): void
    {
        $dsn = ['id' => 'msg-123', 'return' => 'headers', 'notify' => 'success'];
        Provider::init(self::PROVIDER_OPTIONS, self::SETTINGS)->send([
            'to' => 'recipient@example.com',
            'subject' => 'Test Subject',
            'text' => 'Test content',
            'dsn' => $dsn,
        ]);

        self::assertSame($dsn, $this->sent()['dsn']);
    }

    public function testPassesPerMessageOAuth2AuthWithOnlyAllowedFields(): void
    {
        Provider::init(self::PROVIDER_OPTIONS, self::SETTINGS)->send([
            'to' => 'recipient@example.com',
            'subject' => 'Test Subject',
            'text' => 'Test content',
            'auth' => [
                'user' => 'user@gmail.com',
                'refreshToken' => '1/xxx',
                'accessToken' => 'ya29.xxx',
                'expires' => 1234567890,
                'clientSecret' => 'not-forwarded',
            ],
        ]);

        self::assertSame([
            'user' => 'user@gmail.com',
            'refreshToken' => '1/xxx',
            'accessToken' => 'ya29.xxx',
            'expires' => 1234567890,
        ], $this->sent()['auth']);
    }

    public function testDoesNotPassUnknownPropertiesToSendMail(): void
    {
        Provider::init(self::PROVIDER_OPTIONS, self::SETTINGS)->send([
            'to' => 'recipient@example.com',
            'subject' => 'Test Subject',
            'text' => 'Test content',
            'unknownInjected' => 'x',
        ]);

        $allowedKeys = [
            'from', 'to', 'cc', 'bcc', 'replyTo', 'sender', 'subject', 'text', 'html', 'watchHtml', 'amp',
            'attachments', 'alternatives', 'headers', 'priority', 'messageId', 'date', 'xMailer', 'inReplyTo',
            'references', 'textEncoding', 'encoding', 'normalizeHeaderKey', 'icalEvent', 'list', 'envelope',
            'dkim', 'attachDataUrls', 'disableUrlAccess', 'disableFileAccess', 'raw', 'dsn', 'auth',
        ];
        foreach (array_keys($this->sent()) as $key) {
            self::assertContains($key, $allowedKeys);
        }
    }

    public function testPassesIcalEventAndListOptions(): void
    {
        $icalEvent = ['method' => 'REQUEST', 'content' => 'BEGIN:VCALENDAR...'];
        $list = ['unsubscribe' => ['url' => 'https://example.com/unsubscribe', 'comment' => 'Unsubscribe']];
        Provider::init(self::PROVIDER_OPTIONS, self::SETTINGS)->send([
            'to' => 'recipient@example.com',
            'subject' => 'Test Subject',
            'text' => 'Test content',
            'icalEvent' => $icalEvent,
            'list' => $list,
        ]);

        self::assertSame($icalEvent, $this->sent()['icalEvent']);
        self::assertSame($list, $this->sent()['list']);
    }

    public function testPassesThreadingOptions(): void
    {
        Provider::init(self::PROVIDER_OPTIONS, self::SETTINGS)->send([
            'to' => 'recipient@example.com',
            'subject' => 'Re: Test Subject',
            'text' => 'Reply content',
            'inReplyTo' => '<original-msg-id@example.com>',
            'references' => ['<original-msg-id@example.com>', '<prev-msg-id@example.com>'],
        ]);

        self::assertSame('<original-msg-id@example.com>', $this->sent()['inReplyTo']);
        self::assertSame(['<original-msg-id@example.com>', '<prev-msg-id@example.com>'], $this->sent()['references']);
    }

    public function testPassesSenderForOnBehalfOfEmails(): void
    {
        Provider::init(self::PROVIDER_OPTIONS, self::SETTINGS)->send([
            'to' => 'recipient@example.com',
            'subject' => 'Test Subject',
            'text' => 'Test content',
            'sender' => 'actual-sender@example.com',
        ]);

        self::assertSame('actual-sender@example.com', $this->sent()['sender']);
    }

    public function testPassesPerMessageDkimOptions(): void
    {
        $dkim = ['domainName' => 'example.com', 'keySelector' => 'default', 'privateKey' => "-----BEGIN RSA PRIVATE KEY-----\n..."];
        Provider::init(self::PROVIDER_OPTIONS, self::SETTINGS)->send([
            'to' => 'recipient@example.com',
            'subject' => 'Test Subject',
            'text' => 'Test content',
            'dkim' => $dkim,
        ]);

        self::assertSame($dkim, $this->sent()['dkim']);
    }

    public function testAlwaysForcesDisableFileAccessAndDisableUrlAccess(): void
    {
        Provider::init(self::PROVIDER_OPTIONS, self::SETTINGS)->send([
            'to' => 'recipient@example.com',
            'subject' => 'Test Subject',
            'text' => 'Test content',
            'disableFileAccess' => false,
            'disableUrlAccess' => false,
        ]);

        self::assertTrue($this->sent()['disableFileAccess']);
        self::assertTrue($this->sent()['disableUrlAccess']);
    }

    public function testAppliesTheFileAndUrlAccessGuardOnSendsWithPathOrHrefAttachments(): void
    {
        $attachments = [
            ['filename' => 'passwd', 'path' => '/etc/passwd'],
            ['filename' => 'secret', 'href' => 'http://169.254.169.254/latest/meta-data/'],
        ];
        Provider::init(self::PROVIDER_OPTIONS, self::SETTINGS)->send([
            'to' => 'recipient@example.com',
            'subject' => 'Test Subject',
            'text' => 'Test content',
            'attachments' => $attachments,
        ]);

        self::assertSame($attachments, $this->sent()['attachments']);
        self::assertTrue($this->sent()['disableFileAccess']);
        self::assertTrue($this->sent()['disableUrlAccess']);
    }

    public function testPassesEncodingAndMetadataOptions(): void
    {
        $date = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        Provider::init(self::PROVIDER_OPTIONS, self::SETTINGS)->send([
            'to' => 'recipient@example.com',
            'subject' => 'Test Subject',
            'text' => 'Test content',
            'messageId' => '<custom-id@example.com>',
            'date' => $date,
            'textEncoding' => 'base64',
            'xMailer' => false,
        ]);

        self::assertSame('<custom-id@example.com>', $this->sent()['messageId']);
        self::assertSame($date, $this->sent()['date']);
        self::assertSame('base64', $this->sent()['textEncoding']);
        self::assertFalse($this->sent()['xMailer']);
    }

    public function testPassesRawMimeContent(): void
    {
        $rawMime = "From: sender@example.com\r\nTo: recipient@example.com\r\nSubject: Raw\r\n\r\nBody";
        Provider::init(self::PROVIDER_OPTIONS, self::SETTINGS)->send([
            'to' => 'recipient@example.com',
            'subject' => 'Test Subject',
            'text' => 'Test content',
            'raw' => $rawMime,
        ]);

        self::assertSame($rawMime, $this->sent()['raw']);
    }

    public function testPassesAlternativesAndAttachDataUrls(): void
    {
        $alternatives = [['contentType' => 'text/x-web-markdown', 'content' => '**Bold**']];
        Provider::init(self::PROVIDER_OPTIONS, self::SETTINGS)->send([
            'to' => 'recipient@example.com',
            'subject' => 'Test Subject',
            'text' => 'Test content',
            'alternatives' => $alternatives,
            'attachDataUrls' => true,
        ]);

        self::assertSame($alternatives, $this->sent()['alternatives']);
        self::assertTrue($this->sent()['attachDataUrls']);
    }

    // verify

    public function testVerifiesTheTransporterConfiguration(): void
    {
        $instance = Provider::init(['host' => 'smtp.example.com', 'port' => 587], self::SETTINGS);

        self::assertTrue($instance->verify());
        self::assertSame(1, $this->transporter->verifyCalls);
    }

    public function testThrowsWhenVerificationFails(): void
    {
        $this->transporter->verifyError = new \RuntimeException('Connection refused');
        $instance = Provider::init(['host' => 'invalid.example.com', 'port' => 587], self::SETTINGS);

        $this->expectExceptionMessage('Connection refused');
        $instance->verify();
    }

    // isIdle

    public function testReturnsTheIdleStateFromThePooledTransporter(): void
    {
        $this->transporter->idle = false;
        $instance = Provider::init(['host' => 'smtp.example.com', 'port' => 587, 'pool' => true], self::SETTINGS);

        self::assertFalse($instance->isIdle());
        self::assertSame(1, $this->transporter->isIdleCalls);
    }

    // close

    public function testClosesAllConnections(): void
    {
        Provider::init(['host' => 'smtp.example.com', 'port' => 587, 'pool' => true], self::SETTINGS)->close();

        self::assertSame(1, $this->transporter->closeCalls);
    }

    // getCapabilities

    public function testReturnsTransportInfoAndAuthTypeWithoutSensitiveData(): void
    {
        $capabilities = Provider::init([
            'host' => 'smtp.gmail.com',
            'port' => 465,
            'secure' => true,
            'auth' => ['user' => 'test@gmail.com', 'pass' => 'secret-password'],
        ], self::SETTINGS)->getCapabilities();

        self::assertSame(['host' => 'smtp.gmail.com', 'port' => 465, 'secure' => true], $capabilities['transport'] ?? null);
        self::assertSame(['type' => 'login', 'user' => 'test@gmail.com'], $capabilities['auth'] ?? null);
        self::assertStringNotContainsString('secret-password', (string) json_encode($capabilities));
    }

    public function testNeverExposesPasswordsTokensOrSecrets(): void
    {
        $capabilities = Provider::init([
            'host' => 'smtp.gmail.com',
            'port' => 465,
            'auth' => [
                'type' => 'OAuth2',
                'user' => 'oauth@gmail.com',
                'clientId' => 'client-id-value',
                'clientSecret' => 'super-secret-value',
                'refreshToken' => 'refresh-token-value',
                'accessToken' => 'access-token-value',
            ],
        ], self::SETTINGS)->getCapabilities();

        $capStr = (string) json_encode($capabilities);
        foreach (['super-secret-value', 'refresh-token-value', 'access-token-value', 'client-id-value'] as $secret) {
            self::assertStringNotContainsString($secret, $capStr);
        }
        self::assertSame('oauth@gmail.com', $capabilities['auth']['user'] ?? null);
    }

    public function testIncludesFeatureFlagsForDkimPoolRateLimitingProxy(): void
    {
        $capabilities = Provider::init([
            'host' => 'smtp.example.com',
            'port' => 587,
            'dkim' => ['domainName' => 'example.com'],
            'pool' => true,
            'rateLimit' => 5,
            'proxy' => 'socks5://proxy.example.com:1080',
        ], self::SETTINGS)->getCapabilities();

        foreach (['dkim', 'pool', 'rateLimiting', 'proxy'] as $feature) {
            self::assertContains($feature, $capabilities['features'] ?? []);
        }
    }

    public function testIncludesOauth2ForOAuth2AuthType(): void
    {
        $capabilities = Provider::init([
            'host' => 'smtp.gmail.com',
            'port' => 465,
            'auth' => ['type' => 'OAuth2', 'user' => 'oauth@gmail.com', 'clientId' => 'client', 'clientSecret' => 'secret'],
        ], self::SETTINGS)->getCapabilities();

        self::assertSame(['type' => 'OAuth2', 'user' => 'oauth@gmail.com'], $capabilities['auth'] ?? null);
        self::assertContains('oauth2', $capabilities['features'] ?? []);
    }
}

final class MockTransporter implements Transporter
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public int $verifyCalls = 0;

    public ?\Throwable $verifyError = null;

    public bool $idle = true;

    public int $isIdleCalls = 0;

    public int $closeCalls = 0;

    public function sendMail(array $mail): array
    {
        $this->sent[] = $mail;

        return ['messageId' => '<test@example.com>'];
    }

    public function verify(): true
    {
        $this->verifyCalls++;
        if ($this->verifyError !== null) {
            throw $this->verifyError;
        }

        return true;
    }

    public function isIdle(): bool
    {
        $this->isIdleCalls++;

        return $this->idle;
    }

    public function close(): void
    {
        $this->closeCalls++;
    }
}
