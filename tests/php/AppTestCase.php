<?php

declare(strict_types=1);

namespace Strapi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Strapi\Core\Core;
use Strapi\Core\Strapi;

/**
 * Boots `examples/getstarted` once per test class on an in-memory SQLite database and sends PSR-7
 * requests through `strapi.server.handle()` — the same path FPM / FrankenPHP take, without a socket.
 */
abstract class AppTestCase extends TestCase
{
    protected static ?Strapi $strapi = null;

    protected static Psr17Factory $factory;

    public static function appDir(): string
    {
        return dirname(__DIR__, 2) . '/examples/getstarted';
    }

    public static function setUpBeforeClass(): void
    {
        putenv('DATABASE_CLIENT=sqlite');
        putenv('DATABASE_FILENAME=:memory:');
        putenv('STRAPI_NO_EXIT=1');
        putenv('LOG_LEVEL=error');
        $_ENV['LOG_LEVEL'] = 'error';
        $_ENV['DATABASE_CLIENT'] = 'sqlite';
        $_ENV['DATABASE_FILENAME'] = ':memory:';

        self::$factory = new Psr17Factory();
        $strapi = Core::createStrapi(['appDir' => self::appDir()]);

        // The content API needs credentials, as upstream (admin's API tokens, users-permissions'
        // JWTs and public role). Register upstream's test-app bypass strategy before loading, as
        // api-tests/strapi.js and tests/api/app/public/index.php do, so it is tried first.
        $strapi->get('auth')->register('content-api', [
            'name' => 'test-auth',
            'authenticate' => static fn (): array => ['authenticated' => true],
            'verify' => static function (): void {
            },
        ]);

        self::$strapi = $strapi->load();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    protected static function strapi(): Strapi
    {
        return self::$strapi ?? throw new \LogicException('Strapi is not booted');
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     */
    protected static function request(string $method, string $uri, ?array $json = null, array $headers = []): ResponseInterface
    {
        $request = self::$factory->createServerRequest($method, 'http://localhost:1337' . $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($json !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody(self::$factory->createStream((string) json_encode($json)));
        }

        return self::strapi()->server()->handle($request);
    }

    /** @return array<string, mixed> */
    protected static function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array{0: ResponseInterface, 1: array<string, mixed>}
     */
    protected static function call(string $method, string $uri, ?array $json = null, array $headers = []): array
    {
        $response = self::request($method, $uri, $json, $headers);

        return [$response, self::json($response)];
    }
}
