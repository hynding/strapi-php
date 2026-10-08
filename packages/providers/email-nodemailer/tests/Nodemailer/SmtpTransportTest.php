<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailNodemailer\Tests\Nodemailer;

require_once __DIR__ . '/RecordingTransport.php';

use PHPUnit\Framework\TestCase;
use Strapi\Provider\EmailNodemailer\Nodemailer\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/** Not an upstream test: the nodemailer substitute (MailComposer + SmtpTransport) on symfony/mailer. */
final class SmtpTransportTest extends TestCase
{
    /** @param array<string, mixed> $mail */
    private static function send(array $mail, array $options = []): array
    {
        $recorder = new RecordingTransport();
        $info = (new SmtpTransport($options, $recorder))->sendMail($mail);

        return [$recorder->last(), $info];
    }

    public function testComposesTextAndHtmlAlternatives(): void
    {
        [$sent, $info] = self::send([
            'from' => 'Strapi <no-reply@strapi.io>',
            'to' => 'a@example.com, "Doe, John" <john@example.com>',
            'cc' => '',
            'bcc' => ['hidden@example.com'],
            'replyTo' => 'support@example.com',
            'subject' => 'Hello',
            'text' => 'Plain body',
            'html' => '<p>HTML body</p>',
        ]);

        self::assertSame('no-reply@strapi.io', $sent['from']);
        self::assertSame(['a@example.com', 'john@example.com', 'hidden@example.com'], $sent['to']);
        self::assertStringContainsString('From: Strapi <no-reply@strapi.io>', $sent['data']);
        self::assertStringContainsString('To: a@example.com, "Doe, John" <john@example.com>', $sent['data']);
        self::assertStringContainsString('Reply-To: support@example.com', $sent['data']);
        self::assertStringNotContainsString('hidden@example.com', $sent['data']);
        self::assertStringContainsString('Subject: Hello', $sent['data']);
        self::assertStringContainsString('multipart/alternative', $sent['data']);
        self::assertLessThan(strpos($sent['data'], 'text/html'), strpos($sent['data'], 'text/plain'));
        self::assertMatchesRegularExpression('/^<[^>]+@strapi\.io>$/', (string) $info['messageId']);
        self::assertSame(['from' => 'no-reply@strapi.io', 'to' => ['a@example.com', 'john@example.com', 'hidden@example.com']], $info['envelope']);
        self::assertSame($info['envelope']['to'], $info['accepted']);
    }

    public function testAttachmentsInlineImagesAndHeaders(): void
    {
        [$sent] = self::send([
            'from' => 'a@b.com',
            'to' => 'c@d.com',
            'subject' => 'S',
            'text' => 'T',
            'html' => '<img src="cid:logo">',
            'attachments' => [
                ['filename' => 'a.txt', 'content' => 'hello'],
                ['filename' => 'b.bin', 'content' => base64_encode('xyz'), 'encoding' => 'base64'],
                ['filename' => 'logo.png', 'content' => 'PNG', 'cid' => 'logo'],
                ['path' => 'data:text/plain;base64,' . base64_encode('from-data-uri')],
            ],
            'headers' => ['X-Custom' => 'v', 'X-Multi' => ['1', '2']],
            'priority' => 'high',
            'messageId' => '<custom-id@example.com>',
            'inReplyTo' => '<orig@example.com>',
            'references' => ['<orig@example.com>', '<prev@example.com>'],
            'list' => ['unsubscribe' => ['url' => 'https://example.com/u', 'comment' => 'Unsubscribe'], 'id' => ['url' => 'list.example.com', 'comment' => 'News']],
            'xMailer' => 'Strapi',
        ]);
        $data = $sent['data'];

        self::assertStringContainsString('multipart/mixed', $data);
        self::assertStringContainsString('multipart/related', $data);
        self::assertStringContainsString('aGVsbG8=', $data);
        self::assertStringContainsString(base64_encode('xyz'), $data);
        self::assertStringContainsString(base64_encode('from-data-uri'), $data);
        self::assertStringContainsString('Content-ID: <logo>', $data);
        self::assertStringContainsString('filename=a.txt', $data);
        self::assertStringContainsString('X-Custom: v', $data);
        self::assertStringContainsString("X-Multi: 1\r\nX-Multi: 2", $data);
        self::assertStringContainsString('X-Priority: 1 (Highest)', $data);
        self::assertStringContainsString('Importance: High', $data);
        self::assertStringContainsString('Message-ID: <custom-id@example.com>', $data);
        self::assertStringContainsString('In-Reply-To: <orig@example.com>', $data);
        self::assertStringContainsString('References: <orig@example.com> <prev@example.com>', $data);
        self::assertStringContainsString('List-Unsubscribe: <https://example.com/u> (Unsubscribe)', $data);
        self::assertStringContainsString('List-ID: "News" <list.example.com>', $data);
        self::assertStringContainsString('X-Mailer: Strapi', $data);
    }

    public function testRefusesFileAndUrlAttachmentsWhenDisabled(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents((string) $file, 'SECRET_CONTENT');

        try {
            self::send(['from' => 'a@b.com', 'to' => 'c@d.com', 'text' => 'T', 'attachments' => [['path' => $file]], 'disableFileAccess' => true]);
            self::fail('file access should be refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('File access rejected for', $e->getMessage());
        }

        try {
            self::send(['from' => 'a@b.com', 'to' => 'c@d.com', 'text' => 'T', 'attachments' => [['href' => 'http://127.0.0.1:1/x']], 'disableUrlAccess' => true]);
            self::fail('url access should be refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Url access rejected for', $e->getMessage());
        }

        // allowed when not disabled
        [$sent] = self::send(['from' => 'a@b.com', 'to' => 'c@d.com', 'text' => 'T', 'attachments' => [['path' => $file]]]);
        self::assertStringContainsString(base64_encode('SECRET_CONTENT'), $sent['data']);
        unlink((string) $file);
    }

    public function testCustomEnvelopeIcalEventAlternativesAndDataUrls(): void
    {
        [$sent] = self::send([
            'from' => 'a@b.com',
            'to' => 'c@d.com',
            'subject' => 'S',
            'text' => 'T',
            'html' => '<img src="data:image/png;base64,' . base64_encode('PNGDATA') . '">',
            'attachDataUrls' => true,
            'icalEvent' => ['method' => 'request', 'content' => "BEGIN:VCALENDAR\r\nEND:VCALENDAR"],
            'alternatives' => [['contentType' => 'text/x-web-markdown', 'content' => '**Bold**']],
            'envelope' => ['from' => 'bounce@b.com', 'to' => ['other@d.com']],
        ]);
        $data = $sent['data'];

        self::assertSame('bounce@b.com', $sent['from']);
        self::assertSame(['other@d.com'], $sent['to']);
        self::assertStringContainsString('text/calendar; charset=utf-8; method=REQUEST', $data);
        self::assertStringContainsString('filename=invite.ics', $data);
        self::assertStringContainsString('text/x-web-markdown', $data);
        self::assertStringContainsString(base64_encode('PNGDATA'), $data);
        self::assertStringContainsString('src=3D"cid:', $data);
        self::assertStringNotContainsString('data:image/png', $data);
    }

    public function testRawMessages(): void
    {
        $raw = "From: sender@example.com\r\nTo: recipient@example.com\r\nMessage-ID: <raw@example.com>\r\nSubject: Raw\r\n\r\nBody";
        [$sent, $info] = self::send(['from' => 'sender@example.com', 'to' => 'recipient@example.com', 'raw' => $raw]);

        self::assertSame($raw, $sent['data']);
        self::assertSame('<raw@example.com>', $info['messageId']);
    }

    public function testNoRecipients(): void
    {
        $this->expectExceptionMessage('No recipients defined');
        self::send(['from' => 'a@b.com', 'to' => '', 'text' => 'T']);
    }

    public function testDkimSigning(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);

        [$sent] = self::send(
            ['from' => 'a@signer.com', 'to' => 'c@d.com', 'subject' => 'S', 'text' => 'T'],
            ['dkim' => ['domainName' => 'signer.com', 'keySelector' => 'strapi', 'privateKey' => $pem]],
        );

        self::assertMatchesRegularExpression('/DKIM-Signature: v=1; q=dns\/txt; a=rsa-sha256;.*d=signer\.com;.*s=strapi;/s', $sent['data']);
    }

    public function testMapsNodemailerTransportOptions(): void
    {
        $transporter = new SmtpTransport([
            'host' => 'smtp.example.com',
            'port' => 2525,
            'secure' => false,
            'ignoreTLS' => true,
            'auth' => ['user' => 'u', 'pass' => 'p'],
            'name' => 'mail.example.org',
            'tls' => ['rejectUnauthorized' => false, 'servername' => 'smtp.example.com'],
            'socketTimeout' => 5000,
            'rateLimit' => 2,
        ]);
        $transport = (new \ReflectionMethod($transporter, 'transport'))->invoke($transporter);

        self::assertInstanceOf(EsmtpTransport::class, $transport);
        self::assertSame('smtp://smtp.example.com:2525', (string) $transport);
        self::assertFalse($transport->isAutoTls());
        self::assertSame('u', $transport->getUsername());
        self::assertSame('p', $transport->getPassword());
        self::assertSame('mail.example.org', $transport->getLocalDomain());
        $stream = $transport->getStream();
        self::assertInstanceOf(\Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream::class, $stream);
        self::assertSame(5.0, $stream->getTimeout());
        self::assertFalse($stream->getStreamOptions()['ssl']['verify_peer']);
        self::assertSame('smtp.example.com', $stream->getStreamOptions()['ssl']['peer_name']);

        $secure = new SmtpTransport(['service' => 'Gmail', 'auth' => ['type' => 'OAuth2', 'user' => 'me@gmail.com', 'accessToken' => 'tok']]);
        $gmail = (new \ReflectionMethod($secure, 'transport'))->invoke($secure);
        self::assertSame('smtps://smtp.gmail.com', (string) $gmail);
        self::assertSame('tok', $gmail->getPassword());
    }

    public function testStreamAndJsonTransportsSendNothing(): void
    {
        $mail = ['from' => 'a@b.com', 'to' => 'c@d.com', 'subject' => 'Hello', 'text' => 'T'];

        $stream = (new SmtpTransport(['streamTransport' => true]))->sendMail($mail);
        self::assertStringContainsString('Subject: Hello', $stream['message']);
        self::assertSame(['from' => 'a@b.com', 'to' => ['c@d.com']], $stream['envelope']);

        $json = json_decode((new SmtpTransport(['jsonTransport' => true]))->sendMail($mail)['message'], true);
        self::assertSame('Hello', $json['subject']);
        self::assertMatchesRegularExpression('/^<.+@b\.com>$/', $json['messageId']);
    }
}
