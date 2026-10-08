<?php

declare(strict_types=1);

namespace Strapi\Email\Tests\Controllers;

require_once dirname(__DIR__) . '/EmailTestCase.php';

use Strapi\Email\Tests\EmailTestCase;
use Strapi\Email\Tests\RecordingEmailProvider;
use Strapi\Utils\Errors\ApplicationError;

/** controllers/email.ts (no upstream unit test). */
final class EmailTest extends EmailTestCase
{
    private function controller(): object
    {
        return self::strapi()->plugin('email')->controller('email');
    }

    public function testSendAnswersAnEmptyObject(): void
    {
        $provider = new RecordingEmailProvider();
        self::useProvider($provider);
        $ctx = self::ctx('POST', '/email', ['to' => 'a@b.c', 'subject' => 'S', 'text' => 'T']);

        $this->controller()->send($ctx);

        self::assertSame(200, $ctx->status());
        self::assertSame('{}', json_encode($ctx->body()));
        self::assertSame([['to' => 'a@b.c', 'subject' => 'S', 'text' => 'T']], $provider->sent);
    }

    public function testSendTurnsA400ProviderErrorIntoAnApplicationError(): void
    {
        $provider = new RecordingEmailProvider();
        $provider->error = new class ('Invalid recipient') extends \RuntimeException {
            public int $statusCode = 400;
        };
        self::useProvider($provider);

        try {
            $this->controller()->send(self::ctx('POST', '/email', ['to' => 'x']));
            self::fail('expected an error');
        } catch (ApplicationError $e) {
            self::assertSame('Invalid recipient', $e->getMessage());
            self::assertSame('ApplicationError', $e->name);
        }
    }

    public function testSendWrapsOtherProviderErrors(): void
    {
        $provider = new RecordingEmailProvider();
        $provider->error = new \RuntimeException('SMTP connection refused');
        self::useProvider($provider);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Couldn't send email: SMTP connection refused.");

        $this->controller()->send(self::ctx('POST', '/email', ['to' => 'x']));
    }

    public function testTestRequiresARecipient(): void
    {
        $this->expectException(ApplicationError::class);
        $this->expectExceptionMessage('No recipient(s) are given');

        $this->controller()->test(self::ctx('POST', '/email/test', []));
    }

    public function testTestSendsTheTestMail(): void
    {
        $provider = new RecordingEmailProvider();
        self::useProvider($provider);
        $ctx = self::ctx('POST', '/email/test', ['to' => 'me@example.com']);

        $this->controller()->test($ctx);

        self::assertSame('{}', json_encode($ctx->body()));
        self::assertSame([[
            'to' => 'me@example.com',
            'subject' => 'Strapi test mail to: me@example.com',
            'text' => "Great! You have correctly configured the Strapi email plugin with the sendmail provider. \r\nFor documentation on how to use the email plugin checkout: https://docs.strapi.io/developer-docs/latest/plugins/email.html",
        ]], $provider->sent);
    }

    public function testTestWrapsProviderErrors(): void
    {
        $provider = new RecordingEmailProvider();
        $provider->error = new \RuntimeException('boom');
        self::useProvider($provider);

        $this->expectExceptionMessage("Couldn't send test email: boom.");

        $this->controller()->test(self::ctx('POST', '/email/test', ['to' => 'me@example.com']));
    }

    public function testGetSettingsForAProviderWithoutExtras(): void
    {
        self::useProvider(new RecordingEmailProvider());
        $ctx = self::ctx('GET', '/email/settings');

        $this->controller()->getSettings($ctx);

        self::assertSame(
            '{"config":{"provider":"sendmail","settings":{"defaultFrom":"Strapi <no-reply@strapi.io>"}},"supportsVerify":false}',
            json_encode($ctx->body(), JSON_UNESCAPED_SLASHES),
        );
    }

    public function testGetSettingsReportsVerifyCapabilitiesAndIdleState(): void
    {
        self::useProvider(new class () {
            public function send(array $options): void
            {
            }

            public function verify(): bool
            {
                return true;
            }

            public function isIdle(): bool
            {
                return false;
            }

            /** @return array<string, mixed> */
            public function getCapabilities(): array
            {
                return ['transport' => ['host' => 'smtp.example.com', 'port' => 587], 'features' => ['pool']];
            }
        });
        $ctx = self::ctx('GET', '/email/settings');

        $this->controller()->getSettings($ctx);

        self::assertSame([
            'config' => ['provider' => 'sendmail', 'settings' => ['defaultFrom' => 'Strapi <no-reply@strapi.io>']],
            'supportsVerify' => true,
            'capabilities' => ['transport' => ['host' => 'smtp.example.com', 'port' => 587], 'features' => ['pool']],
            'isIdle' => false,
        ], $ctx->body());
    }

    public function testVerifyNeedsAProviderSupportingIt(): void
    {
        self::useProvider(new RecordingEmailProvider());
        $this->expectException(ApplicationError::class);
        $this->expectExceptionMessage('This email provider does not support connection verification');

        $this->controller()->verify(self::ctx('POST', '/email/verify'));
    }

    public function testVerifyReportsSuccessAndFailure(): void
    {
        $fails = false;
        $provider = new class ($fails) {
            public function __construct(public bool &$fails)
            {
            }

            public function send(array $options): void
            {
            }

            public function verify(): bool
            {
                if ($this->fails) {
                    throw new \RuntimeException('Connection refused');
                }

                return true;
            }
        };
        self::useProvider($provider);
        $ctx = self::ctx('POST', '/email/verify');

        $this->controller()->verify($ctx);
        self::assertSame(['success' => true, 'message' => 'SMTP connection verified successfully'], $ctx->body());

        $fails = true;
        $this->expectExceptionMessage('Connection verification failed: Connection refused');
        $this->controller()->verify(self::ctx('POST', '/email/verify'));
    }
}
