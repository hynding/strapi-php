<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailSendmail\Tests;

require_once dirname(__DIR__, 2) . '/email-nodemailer/tests/Nodemailer/RecordingTransport.php';

use PHPUnit\Framework\TestCase;
use Strapi\Provider\EmailNodemailer\Nodemailer\Nodemailer;
use Strapi\Provider\EmailNodemailer\Nodemailer\SmtpTransport;
use Strapi\Provider\EmailNodemailer\Nodemailer\Transporter;
use Strapi\Provider\EmailNodemailer\Tests\Nodemailer\RecordingTransport;
use Strapi\Provider\EmailSendmail\DirectSmtp;
use Strapi\Provider\EmailSendmail\EmailSendmail as Provider;

/**
 * Port of __tests__/index.vitest.test.ts. Upstream delivers to a minimal SMTP server on a local
 * port; here each per-host transport gets a RecordingTransport, which keeps the SMTP envelope and
 * DATA the real SMTP session would carry (no socket).
 */
final class IndexTest extends TestCase
{
    private RecordingTransport $server;

    /** @var list<array<string, mixed>> */
    private array $transportOptions = [];

    protected function setUp(): void
    {
        $this->server = new RecordingTransport();
        $server = $this->server;
        $options = &$this->transportOptions;
        Nodemailer::$createTransport = static function (array $transportOptions) use ($server, &$options): Transporter {
            $options[] = $transportOptions;

            return new SmtpTransport($transportOptions, $server);
        };
        DirectSmtp::$resolveMx = static fn (string $domain): never => throw new \RuntimeException("unexpected resolveMx({$domain})");
    }

    protected function tearDown(): void
    {
        Nodemailer::$createTransport = null;
        DirectSmtp::$resolveMx = null;
    }

    private function lastData(): string
    {
        return (string) ($this->server->last()['data'] ?? '');
    }

    // init + send

    public function testDeliversMailInDevPortModeToTheTestServer(): void
    {
        $instance = Provider::init(
            ['devPort' => 2525, 'devHost' => '127.0.0.1', 'silent' => true],
            ['defaultFrom' => 'App <noreply@app.com>', 'defaultReplyTo' => 'support@app.com'],
        );

        $instance->send(['to' => 'recipient@target.com', 'cc' => '', 'bcc' => '', 'subject' => 'Test subject', 'text' => 'Plain', 'html' => '<p>HTML</p>']);

        self::assertContains('recipient@target.com', $this->server->last()['to'] ?? []);
        self::assertStringContainsString('Test subject', $this->lastData());
        self::assertStringContainsString('Plain', $this->lastData());
        self::assertSame('noreply@app.com', $this->server->last()['from'] ?? null);
        self::assertSame('127.0.0.1', $this->transportOptions[0]['host']);
        self::assertSame(2525, $this->transportOptions[0]['port']);
        self::assertSame('app.com', $this->transportOptions[0]['name']);
    }

    public function testMergesSilentTrueByDefaultAndAllowsOverride(): void
    {
        $instance = Provider::init(
            ['devPort' => 2525, 'devHost' => '127.0.0.1', 'silent' => false, 'logger' => ['debug' => static function (): void {
            }]],
            ['defaultFrom' => 'noreply@app.com'],
        );

        $instance->send(['to' => 'a@b.com', 'cc' => '', 'bcc' => '', 'subject' => 'S', 'text' => 'T', 'html' => 'T']);

        self::assertGreaterThan(0, count($this->server->messages));
    }

    public function testUsesDefaultFromAndDefaultReplyToWhenOmittedOnSend(): void
    {
        $instance = Provider::init(
            ['devPort' => 2525, 'devHost' => '127.0.0.1', 'silent' => true],
            ['defaultFrom' => 'default-from@x.com', 'defaultReplyTo' => 'default-reply@x.com'],
        );

        $instance->send(['to' => 'r@y.com', 'cc' => '', 'bcc' => '', 'subject' => 'Subj', 'text' => 'Body', 'html' => 'Body']);

        self::assertStringContainsString('default-from@x.com', $this->lastData());
        self::assertStringContainsString('default-reply@x.com', $this->lastData());
    }

    public function testSendsSeparateSmtpSessionsForMultipleRecipientDomains(): void
    {
        DirectSmtp::$resolveMx = static fn (string $domain): array => match ($domain) {
            'one.com', 'two.org' => [['exchange' => '127.0.0.1', 'priority' => 0]],
            default => throw new \RuntimeException("unexpected domain {$domain}"),
        };

        Provider::init(['smtpPort' => 2525, 'silent' => true], ['defaultFrom' => 's@from.com'])
            ->send(['to' => 'a@one.com, b@two.org', 'cc' => '', 'bcc' => '', 'subject' => 'Multi', 'text' => 'Hi', 'html' => 'Hi']);

        self::assertCount(2, $this->server->messages);
        self::assertSame(['a@one.com'], $this->server->messages[0]['to']);
        self::assertSame(['b@two.org'], $this->server->messages[1]['to']);
    }

    public function testPassesThroughExtraMailFieldsLikeAttachments(): void
    {
        Provider::init(['devPort' => 2525, 'devHost' => '127.0.0.1', 'silent' => true], ['defaultFrom' => 'from@test.com'])
            ->send(['to' => 't@test.com', 'cc' => '', 'bcc' => '', 'subject' => 'With attachment', 'text' => 'See attach', 'html' => 'See attach', 'attachments' => [['filename' => 'a.txt', 'content' => 'x']]]);

        self::assertStringContainsString('attachment', $this->lastData());
    }

    public function testAppliesDkimOptionsWhenPrivateKeyIsSet(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);

        Provider::init(
            ['devPort' => 2525, 'devHost' => '127.0.0.1', 'silent' => true, 'dkim' => ['privateKey' => $pem, 'keySelector' => 'strapi']],
            ['defaultFrom' => 'signer@fromdomain.com'],
        )->send(['to' => 'r@remote.com', 'cc' => '', 'bcc' => '', 'subject' => 'Signed', 'text' => 'T', 'html' => 'T']);

        self::assertMatchesRegularExpression('/DKIM-Signature/i', $this->lastData());
        self::assertStringContainsString('d=fromdomain.com', $this->lastData());
    }

    // errors

    public function testRejectsWhenSendHasNoRecipients(): void
    {
        $this->expectExceptionMessage('No recipients');

        Provider::init(['devPort' => 2525, 'devHost' => '127.0.0.1', 'silent' => true], ['defaultFrom' => 'a@b.com'])
            ->send(['to' => '', 'cc' => '', 'bcc' => '', 'subject' => 'x', 'text' => 'y', 'html' => 'y']);
    }

    // attachment file/URL access

    public function testDoesNotReadALocalFileReferencedByAttachmentsPath(): void
    {
        $secretPath = (string) tempnam(sys_get_temp_dir(), 'sendmail-attach-');
        $secret = 'FILE_ACCESS_SHOULD_BE_BLOCKED_9f3a';
        file_put_contents($secretPath, $secret);
        $errors = [];

        try {
            Provider::init(
                ['devPort' => 2525, 'devHost' => '127.0.0.1', 'logger' => ['error' => static function (mixed ...$args) use (&$errors): void {
                    array_push($errors, ...$args);
                }]],
                ['defaultFrom' => 'a@b.com'],
            )->send(['to' => 'recipient@target.com', 'cc' => '', 'bcc' => '', 'subject' => 'S', 'text' => 'T', 'html' => 'T', 'attachments' => [['path' => $secretPath]]]);
            self::fail('expected the send to be rejected');
        } catch (\RuntimeException) {
        } finally {
            unlink($secretPath);
        }

        self::assertTrue(self::anyAccessRejected($errors));
        self::assertStringNotContainsString($secret, $this->lastData());
    }

    public function testDoesNotFetchAUrlReferencedByAttachmentsHref(): void
    {
        $errors = [];
        try {
            Provider::init(
                ['devPort' => 2525, 'devHost' => '127.0.0.1', 'logger' => ['error' => static function (mixed ...$args) use (&$errors): void {
                    array_push($errors, ...$args);
                }]],
                ['defaultFrom' => 'a@b.com'],
            )->send(['to' => 'recipient@target.com', 'cc' => '', 'bcc' => '', 'subject' => 'S', 'text' => 'T', 'html' => 'T', 'attachments' => [['href' => 'http://127.0.0.1:1/internal']]]);
            self::fail('expected the send to be rejected');
        } catch (\RuntimeException) {
        }

        self::assertTrue(self::anyAccessRejected($errors));
    }

    public function testStillDeliversAnInlineContentAttachment(): void
    {
        Provider::init(['devPort' => 2525, 'devHost' => '127.0.0.1', 'silent' => true], ['defaultFrom' => 'a@b.com'])
            ->send(['to' => 'recipient@target.com', 'cc' => '', 'bcc' => '', 'subject' => 'S', 'text' => 'T', 'html' => 'T', 'attachments' => [['filename' => 'a.txt', 'content' => 'hello']]]);

        // base64 of "hello"
        self::assertStringContainsString('aGVsbG8=', $this->lastData());
    }

    /** @param list<mixed> $errors */
    private static function anyAccessRejected(array $errors): bool
    {
        foreach ($errors as $e) {
            $message = $e instanceof \Throwable ? $e->getMessage() : (is_string($e) ? $e : '');
            if (preg_match('/access rejected/i', $message) === 1) {
                return true;
            }
        }

        return false;
    }
}
