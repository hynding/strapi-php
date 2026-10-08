<?php

declare(strict_types=1);

namespace Strapi\Plugin\Sentry\Tests\Services;

use PHPUnit\Framework\TestCase;
use Strapi\Plugin\Sentry\Sdk\Client;
use Strapi\Plugin\Sentry\Sdk\Scope;
use Strapi\Plugin\Sentry\Services\Sentry;
use Strapi\Plugin\Sentry\Tests\SentryTestApp;

require_once dirname(__DIR__) . '/SentryTestApp.php';

/**
 * Port of server/src/services/__tests__/sentry.vitest.test.ts.
 *
 * Upstream mocks `@sentry/node` (an `init` that throws on an invalid DSN, a `captureException`
 * spy). Here the real client runs with a recording transport: "captureException was called" is
 * "an envelope was posted".
 */
final class SentryTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function defaultConfig(): array
    {
        return (require dirname(__DIR__, 2) . '/server/src/config.php')['default'];
    }

    public function testDisablesSentryWhenNoDsnIsProvided(): void
    {
        $app = new SentryTestApp(self::defaultConfig());
        $sentryService = Sentry::createSentryService($app->strapi);
        $sentryService->init();

        self::assertCount(1, array_filter($app->messages('info'), static fn (string $m): bool => preg_match('/disabled/i', $m) === 1));

        $instance = $sentryService->getInstance();
        self::assertNull($instance);
    }

    public function testDisablesSentryWhenAnInvalidDsnIsProvided(): void
    {
        $app = new SentryTestApp(['dsn' => SentryTestApp::INVALID_DSN]);
        $sentryService = Sentry::createSentryService($app->strapi);
        $sentryService->init();

        self::assertCount(1, array_filter($app->messages('warning'), static fn (string $m): bool => preg_match('/could not set up sentry/i', $m) === 1));

        $instance = $sentryService->getInstance();
        self::assertNull($instance);
    }

    public function testDoesntSendEventsBeforeInit(): void
    {
        $app = new SentryTestApp(self::defaultConfig());
        $sentryService = Sentry::createSentryService($app->strapi);
        $sentryService->sendError(new \Exception());

        self::assertCount(1, array_filter($app->messages('warning'), static fn (string $m): bool => preg_match('/cannot send event/i', $m) === 1));
    }

    public function testInitializesAndSendsErrors(): void
    {
        $app = new SentryTestApp(['dsn' => SentryTestApp::VALID_DSN, 'sendMetadata' => true]);
        $app->strapi->config()->set('plugin::sentry.init', ['transport' => $app->transport()]);
        $sentryService = Sentry::createSentryService($app->strapi);
        $sentryService->init();

        // Saves the instance correctly
        $instance = $sentryService->getInstance();
        self::assertNotNull($instance);

        // Doesn't allow re-init
        $sentryService->init();
        self::assertSame($instance, $sentryService->getInstance());

        // Send error
        $error = new \Exception('an error');
        $calls = 0;
        $sentryService->sendError($error, static function (Scope $scope) use (&$calls): void {
            ++$calls;
        });
        self::assertSame(1, $calls);
        self::assertCount(1, $app->requests);
    }

    public function testDoesNotSendMetadataWhenTheOptionIsDisabled(): void
    {
        // Init with metadata option disabled
        $app = new SentryTestApp(['dsn' => SentryTestApp::VALID_DSN, 'sendMetadata' => false]);
        $app->strapi->config()->set('plugin::sentry.init', ['transport' => $app->transport()]);
        $sentryService = Sentry::createSentryService($app->strapi);
        $sentryService->init();

        // Send error
        $calls = 0;
        $sentryService->sendError(new \Exception('an error'), static function () use (&$calls): void {
            ++$calls;
        });
        self::assertSame(0, $calls);
        self::assertCount(1, $app->requests);
    }

    // Not upstream: what the port adds on top of the mocked SDK

    public function testScopeMetadataEndsUpInTheEventAndDoesNotLeak(): void
    {
        $app = new SentryTestApp(['dsn' => SentryTestApp::VALID_DSN, 'sendMetadata' => true, 'init' => ['release' => 'my-app@1.0.0']]);
        $app->strapi->config()->set('plugin::sentry.init.transport', $app->transport());
        $app->strapi->config()->set('environment', 'staging');
        $sentryService = Sentry::createSentryService($app->strapi)->init();

        $sentryService->sendError(new \RuntimeException('boom'), static function (Scope $scope): void {
            $scope->setTag('my_custom_tag', 'Tag value');
            $scope->setExtra('answer', 42);
            $scope->setContext('order', ['id' => 7]);
        });
        $sentryService->sendError(new \RuntimeException('second'));

        $first = $app->event(0);
        self::assertSame(['my_custom_tag' => 'Tag value'], $first['tags']);
        self::assertSame(['answer' => 42], $first['extra']);
        self::assertSame(['id' => 7], $first['contexts']['order']);
        self::assertSame('staging', $first['environment']);
        self::assertSame('my-app@1.0.0', $first['release']);
        self::assertSame('error', $first['level']);
        self::assertSame(Client::SDK_NAME, $first['sdk']['name']);

        $second = $app->event(1);
        self::assertArrayNotHasKey('tags', $second);
        self::assertArrayNotHasKey('extra', $second);
    }

    public function testATransportFailureIsLoggedNotThrown(): void
    {
        $app = new SentryTestApp(['dsn' => SentryTestApp::VALID_DSN, 'sendMetadata' => true, 'init' => [
            'transport' => static function (): never {
                throw new \RuntimeException('fetch failed: connection refused');
            },
        ]]);
        $sentryService = Sentry::createSentryService($app->strapi)->init();

        $sentryService->sendError(new \RuntimeException('boom'));

        self::assertContains('Could not send the event to Sentry: fetch failed: connection refused', $app->messages('warning'));
    }

    public function testWithoutADsnNothingIsSent(): void
    {
        $app = new SentryTestApp([...self::defaultConfig()]);
        $app->strapi->config()->set('plugin::sentry.init', ['transport' => $app->transport()]);
        $sentryService = Sentry::createSentryService($app->strapi)->init();
        $sentryService->sendError(new \RuntimeException('boom'));

        self::assertNull($sentryService->getInstance());
        self::assertSame([], $app->requests);
    }
}
