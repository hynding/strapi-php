<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailSendmail\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Provider\EmailNodemailer\Nodemailer\Nodemailer;
use Strapi\Provider\EmailNodemailer\Nodemailer\Transporter;
use Strapi\Provider\EmailSendmail\DirectSmtp;

/**
 * Port of __tests__/mx-fallback.vitest.test.ts (`vi.mock` of `dns/promises`, `os` and `nodemailer`
 * → DirectSmtp::$resolveMx, DirectSmtp::$hostname and Nodemailer::$createTransport).
 */
final class MxFallbackTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $createTransportCalls = [];

    /** @var list<array<string, mixed>> */
    private array $sendMailCalls = [];

    /** @var list<\Throwable|null> per call: an error to throw, or null to succeed */
    private array $sendMailResults = [];

    /** @var (\Closure(array<string, mixed>): ?\Throwable)|null per host behavior */
    private ?\Closure $sendMailByHost = null;

    private int $hostnameCalls = 0;

    private string $hostname = 'test-host.fallback';

    protected function setUp(): void
    {
        $test = $this;
        DirectSmtp::$hostname = static function () use ($test): string {
            $test->hostnameCalls++;

            return $test->hostname;
        };
        Nodemailer::$createTransport = static function (array $options) use ($test): Transporter {
            $test->createTransportCalls[] = $options;

            return new class ($test, $options) implements Transporter {
                /** @param array<string, mixed> $options */
                public function __construct(private readonly MxFallbackTest $test, private readonly array $options)
                {
                }

                public function sendMail(array $mail): array
                {
                    return $this->test->recordSendMail($mail, $this->options);
                }

                public function verify(): true
                {
                    return true;
                }

                public function isIdle(): bool
                {
                    return true;
                }

                public function close(): void
                {
                }
            };
        };
    }

    protected function tearDown(): void
    {
        DirectSmtp::$hostname = null;
        DirectSmtp::$resolveMx = null;
        Nodemailer::$createTransport = null;
    }

    /**
     * @param array<string, mixed> $mail
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function recordSendMail(array $mail, array $options): array
    {
        $this->sendMailCalls[] = $mail;
        $error = $this->sendMailByHost !== null ? ($this->sendMailByHost)($options) : array_shift($this->sendMailResults);
        if ($error !== null) {
            throw $error;
        }

        return ['messageId' => '<ok@test>'];
    }

    public function testUsesOsHostnameAsSmtpClientNameWhenFromHasNoDomainPart(): void
    {
        DirectSmtp::sendDirectSmtp(
            ['from' => 'not-an-email', 'to' => 'user@to.com', 'subject' => 's', 'text' => 't'],
            ['devPort' => 1025, 'devHost' => '127.0.0.1', 'silent' => true],
        );

        self::assertGreaterThan(0, $this->hostnameCalls);
        self::assertSame('test-host.fallback', $this->createTransportCalls[0]['name']);
    }

    public function testUsesLocalhostWhenFromHasNoDomainAndHostnameIsEmpty(): void
    {
        $this->hostname = '';

        DirectSmtp::sendDirectSmtp(
            ['from' => 'not-an-email', 'to' => 'user@to.com', 'subject' => 's', 'text' => 't'],
            ['devPort' => 1025, 'devHost' => '127.0.0.1', 'silent' => true],
        );

        self::assertSame('localhost', $this->createTransportCalls[0]['name']);
    }

    public function testUsesOsHostnameForDkimDomainNameWhenFromHasNoDomainPart(): void
    {
        DirectSmtp::sendDirectSmtp(
            ['from' => 'not-an-email', 'to' => 'user@to.com', 'subject' => 's', 'text' => 't'],
            ['devPort' => 1025, 'devHost' => '127.0.0.1', 'silent' => true, 'dkim' => ['privateKey' => 'test-key', 'keySelector' => 'default']],
        );

        self::assertSame('test-host.fallback', $this->sendMailCalls[0]['dkim']['domainName'] ?? null);
    }

    public function testPassesTlsRejectUnauthorizedToCreateTransport(): void
    {
        DirectSmtp::sendDirectSmtp(
            ['from' => 'a@b.com', 'to' => 'c@b.com', 'subject' => 's', 'text' => 't'],
            ['devPort' => 1025, 'devHost' => '127.0.0.1', 'rejectUnauthorized' => false, 'silent' => true],
        );

        self::assertTrue($this->createTransportCalls[0]['ignoreTLS']);
        self::assertSame(['rejectUnauthorized' => false], $this->createTransportCalls[0]['tls']);
    }

    public function testRetriesTheNextMxHostWhenTheFirstConnectionFails(): void
    {
        DirectSmtp::$resolveMx = static fn (): array => [
            ['exchange' => 'mx1.bad.example', 'priority' => 10],
            ['exchange' => 'mx2.good.example', 'priority' => 20],
        ];
        $this->sendMailResults = [new \RuntimeException('ECONNREFUSED'), null];

        DirectSmtp::sendDirectSmtp(['from' => 'from@src.com', 'to' => 'to@dst.com', 'subject' => 's', 'text' => 't'], ['smtpPort' => 25, 'silent' => true]);

        self::assertCount(2, $this->createTransportCalls);
        self::assertSame('mx1.bad.example', $this->createTransportCalls[0]['host']);
        self::assertSame('mx2.good.example', $this->createTransportCalls[1]['host']);
        self::assertCount(2, $this->sendMailCalls);
    }

    public function testDoesNotFailTheWholeSendWhenAtLeastOneRecipientDomainSucceeds(): void
    {
        DirectSmtp::$resolveMx = static fn (string $domain): array => match ($domain) {
            'bad.example' => [['exchange' => 'mx.bad.example', 'priority' => 10]],
            'good.example' => [['exchange' => 'mx.good.example', 'priority' => 10]],
            default => throw new \RuntimeException("unexpected domain {$domain}"),
        };
        $this->sendMailByHost = static fn (array $options): ?\Throwable => $options['host'] === 'mx.bad.example' ? new \RuntimeException('ECONNREFUSED') : null;

        DirectSmtp::sendDirectSmtp(
            ['from' => 'from@src.com', 'to' => 'bad@bad.example, ok@good.example', 'subject' => 's', 'text' => 't'],
            ['smtpPort' => 25, 'silent' => true],
        );

        self::assertCount(2, $this->sendMailCalls);
    }
}
