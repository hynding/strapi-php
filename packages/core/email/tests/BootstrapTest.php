<?php

declare(strict_types=1);

namespace Strapi\Email\Tests;

require_once __DIR__ . '/EmailTestCase.php';

use PHPUnit\Framework\Attributes\DataProvider;
use Strapi\Email\Bootstrap;
use Strapi\Provider\EmailNodemailer\EmailNodemailer;
use Strapi\Provider\EmailSendmail\EmailSendmail;

/** Port of server/src/__tests__/bootstrap.test.ts (plus provider loading). */
final class BootstrapTest extends EmailTestCase
{
    private string|false $originalNodeEnv = false;

    private ?object $originalPermissionService = null;

    /** @var list<list<array<string, mixed>>> */
    private array $registered = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalNodeEnv = getenv('NODE_ENV');
        $this->originalPermissionService = self::strapi()->get('services')->get('admin::permission');
        $registered = &$this->registered;
        // the app is already bootstrapped: the admin's action provider is frozen
        self::strapi()->get('services')->set('admin::permission', new class ($registered) {
            public object $actionProvider;

            /** @param list<list<array<string, mixed>>> $registered */
            public function __construct(array &$registered)
            {
                $this->actionProvider = new class ($registered) {
                    /** @param list<list<array<string, mixed>>> $registered */
                    public function __construct(private array &$registered)
                    {
                    }

                    /** @param list<array<string, mixed>> $actions */
                    public function registerMany(array $actions): void
                    {
                        $this->registered[] = $actions;
                    }
                };
            }
        });
    }

    protected function tearDown(): void
    {
        $this->originalNodeEnv === false ? putenv('NODE_ENV') : putenv("NODE_ENV={$this->originalNodeEnv}");
        self::strapi()->get('services')->set('admin::permission', $this->originalPermissionService);
        self::strapi()->config()->set('plugin::email.provider', 'sendmail');
        parent::tearDown();
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function nodeEnvs(): iterable
    {
        yield 'development' => ['development', true];
        yield 'test' => ['test', false];
        yield 'production' => ['production', false];
    }

    #[DataProvider('nodeEnvs')]
    public function testLogsSendmailMigrationGuidanceOnlyInDevelopment(string $nodeEnv, bool $shouldWarn): void
    {
        putenv("NODE_ENV={$nodeEnv}");
        $logs = self::recordLogs();

        (new Bootstrap())(self::strapi());

        $warnings = array_filter($logs->messages('warning'), static fn (string $m): bool => str_contains($m, '[email]: The "sendmail" email provider is still supported'));
        self::assertSame($shouldWarn, $warnings !== []);
    }

    public function testDoesNotLogSendmailMigrationGuidanceForOtherProvidersInDevelopment(): void
    {
        putenv('NODE_ENV=development');
        self::strapi()->config()->set('plugin::email.provider', 'nodemailer');
        $logs = self::recordLogs();

        (new Bootstrap())(self::strapi());

        self::assertSame([], $logs->messages('warning'));
        self::assertInstanceOf(EmailNodemailer::class, self::strapi()->plugin('email')->provider);
    }

    public function testLoadsTheDefaultSendmailProviderAndRegistersTheSettingsAction(): void
    {
        (new Bootstrap())(self::strapi());

        self::assertInstanceOf(EmailSendmail::class, self::strapi()->plugin('email')->provider);
        self::assertSame([[[
            'section' => 'settings',
            'category' => 'email',
            'displayName' => 'Access the Email Settings page',
            'uid' => 'settings.read',
            'pluginName' => 'email',
        ]]], $this->registered);
    }

    public function testResolvesProviderNamesCaseInsensitively(): void
    {
        $provider = Bootstrap::createProvider(self::strapi(), ['provider' => 'NodeMailer', 'providerOptions' => ['host' => 'smtp.example.com']]);

        self::assertInstanceOf(EmailNodemailer::class, $provider);
        self::assertSame(['transport' => ['host' => 'smtp.example.com']], $provider->getCapabilities());
    }

    public function testLoadsACustomProviderByClassName(): void
    {
        $provider = Bootstrap::createProvider(self::strapi(), ['provider' => CustomEmailProvider::class, 'providerOptions' => ['a' => 1], 'settings' => ['defaultFrom' => 'x@y.z']]);

        self::assertInstanceOf(CustomEmailProvider::class, $provider);
        self::assertSame(['a' => 1], $provider->options);
        self::assertSame(['defaultFrom' => 'x@y.z'], $provider->settings);
    }

    public function testAnUnknownProviderCannotBeLoaded(): void
    {
        $this->expectExceptionMessage('Could not load email provider "nope-not-installed".');
        Bootstrap::createProvider(self::strapi(), ['provider' => 'nope-not-installed']);
    }
}

final class CustomEmailProvider
{
    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $settings
     */
    private function __construct(public readonly array $options, public readonly array $settings)
    {
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $settings
     */
    public static function init(array $options, array $settings): self
    {
        return new self($options, $settings);
    }

    /** @param array<string, mixed> $options */
    public function send(array $options): void
    {
    }
}
