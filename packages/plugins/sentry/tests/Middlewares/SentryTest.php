<?php

declare(strict_types=1);

namespace Strapi\Plugin\Sentry\Tests\Middlewares;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Strapi\Plugin\Sentry\Bootstrap;
use Strapi\Plugin\Sentry\Tests\SentryTestApp;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ValidationError;

require_once dirname(__DIR__) . '/SentryTestApp.php';

/**
 * Not an upstream test (upstream has none for the middleware): bootstrap installs a global
 * middleware that reports errors thrown by requests, with the request data, the transaction /
 * strapi_version / method tags, and rethrows them.
 */
final class SentryTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private static function app(array $config): SentryTestApp
    {
        $app = new SentryTestApp($config);
        $app->strapi->get('plugins')->add('sentry', require dirname(__DIR__, 2) . '/strapi-server.php');
        $app->strapi->config()->set('plugin::sentry', [
            ...(require dirname(__DIR__, 2) . '/server/src/config.php')['default'],
            ...$config,
        ]);
        $app->strapi->config()->set('info.strapi', '5.56.0');

        $app->strapi->server()->routes([
            'type' => 'content-api',
            'routes' => [
                [
                    'method' => 'POST',
                    'path' => '/articles/:id/fail',
                    'handler' => static function (Context $ctx): never {
                        throw new ValidationError('title is required', ['field' => 'title']);
                    },
                    'config' => ['auth' => false],
                ],
                [
                    'method' => 'GET',
                    'path' => '/ok',
                    'handler' => static function (Context $ctx): void {
                        $ctx->setBody(['ok' => true]);
                    },
                    'config' => ['auth' => false],
                ],
            ],
        ]);

        return $app;
    }

    public function testReportsRequestErrorsAndRethrowsThem(): void
    {
        $app = self::app(['dsn' => SentryTestApp::VALID_DSN]);
        $app->strapi->config()->set('plugin::sentry.init', ['transport' => $app->transport()]);
        (new Bootstrap())($app->strapi);

        $request = (new ServerRequest('POST', 'http://localhost:1337/api/articles/12/fail?locale=fr', ['Content-Type' => 'application/json', 'Cookie' => 'a=1']))
            ->withParsedBody(['data' => ['title' => '']]);

        try {
            $app->strapi->server()->handle($request);
            self::fail('the error should be rethrown');
        } catch (ValidationError $error) {
            self::assertSame('title is required', $error->getMessage());
        }

        self::assertCount(1, $app->requests);
        $event = $app->event();
        self::assertSame(ValidationError::class, $event['exception']['values'][0]['type']);
        self::assertSame([
            'transaction' => 'POST /api/articles/:id/fail',
            'strapi_version' => '5.56.0',
            'method' => 'POST',
        ], $event['tags']);
        self::assertSame('POST', $event['request']['method']);
        self::assertSame('http://localhost:1337/api/articles/12/fail?locale=fr', $event['request']['url']);
        self::assertSame('locale=fr', $event['request']['query_string']);
        self::assertSame(['a' => '1'], $event['request']['cookies']);
        self::assertSame('{"data":{"title":""}}', $event['request']['data']);
        self::assertSame('application/json', $event['request']['headers']['content-type']);
        self::assertArrayNotHasKey('transaction', $event);
    }

    public function testNoMetadataWhenSendMetadataIsOff(): void
    {
        $app = self::app(['dsn' => SentryTestApp::VALID_DSN, 'sendMetadata' => false]);
        $app->strapi->config()->set('plugin::sentry.init', ['transport' => $app->transport()]);
        (new Bootstrap())($app->strapi);

        try {
            $app->strapi->server()->handle(new ServerRequest('POST', 'http://localhost/api/articles/1/fail'));
        } catch (ValidationError) {
        }

        $event = $app->event();
        self::assertArrayNotHasKey('tags', $event);
        self::assertArrayNotHasKey('request', $event);
    }

    public function testSuccessfulRequestsAreNotReported(): void
    {
        $app = self::app(['dsn' => SentryTestApp::VALID_DSN]);
        $app->strapi->config()->set('plugin::sentry.init', ['transport' => $app->transport()]);
        (new Bootstrap())($app->strapi);

        $response = $app->strapi->server()->handle(new ServerRequest('GET', 'http://localhost/api/ok'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $app->requests);
    }

    public function testWithoutADsnNoMiddlewareIsInstalled(): void
    {
        $app = self::app([]);
        $app->strapi->config()->set('plugin::sentry.init', ['transport' => $app->transport()]);
        (new Bootstrap())($app->strapi);

        try {
            $app->strapi->server()->handle(new ServerRequest('POST', 'http://localhost/api/articles/1/fail'));
        } catch (ValidationError) {
        }

        self::assertSame([], $app->requests);
        self::assertSame(['@strapi/plugin-sentry is disabled because no Sentry DSN was provided'], $app->messages('info'));
    }
}
