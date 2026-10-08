<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Tests;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use Strapi\Core\Core;
use Strapi\Core\Strapi;

/**
 * Upstream unit tests mock `strapi` piece by piece. `Strapi\Core\Strapi` is final, so these
 * tests boot `examples/getstarted` (which loads this plugin) on an in-memory SQLite database.
 *
 * Loaded with `require_once` (the package's autoload-dev is not part of the root autoloader).
 */
final class BootedApp
{
    private static ?Strapi $strapi = null;

    /** One booted app per test run: booting builds the whole GraphQL schema. */
    public static function shared(): Strapi
    {
        return self::$strapi ??= self::boot();
    }

    public static function boot(): Strapi
    {
        putenv('DATABASE_CLIENT=sqlite');
        putenv('DATABASE_FILENAME=:memory:');
        putenv('STRAPI_NO_EXIT=1');
        putenv('LOG_LEVEL=error');
        $_ENV['DATABASE_CLIENT'] = 'sqlite';
        $_ENV['DATABASE_FILENAME'] = ':memory:';
        $_ENV['LOG_LEVEL'] = 'error';

        return Core::createStrapi(['appDir' => dirname(__DIR__, 4) . '/examples/getstarted'])->load();
    }

    /**
     * Replaces `strapi.log` with a recorder.
     *
     * @return \ArrayObject<int, array{level: string, message: string}> the logged messages
     */
    public static function recordLogs(Strapi $strapi): \ArrayObject
    {
        $records = new \ArrayObject();
        $strapi->set('logger', new class ($records) extends AbstractLogger {
            /** @param \ArrayObject<int, array{level: string, message: string}> $records */
            public function __construct(private readonly \ArrayObject $records)
            {
            }

            /** @param array<array-key, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records->append(['level' => (string) $level, 'message' => (string) $message]);
            }
        });

        return $records;
    }

    /**
     * A request through the whole server (global middlewares, router, the GraphQL route).
     *
     * @param array<string, string> $headers
     */
    public static function request(Strapi $strapi, string $method, string $uri, array $headers = [], ?string $body = null): ResponseInterface
    {
        $request = new ServerRequest($method, "http://localhost{$uri}", $headers, $body);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);

        return $strapi->server()->handle($request);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{status: int, body: mixed, headers: array<string, string>}
     */
    public static function graphql(Strapi $strapi, array $body, array $headers = []): array
    {
        $response = self::request($strapi, 'POST', '/graphql', ['Content-Type' => 'application/json', ...$headers], (string) json_encode($body));

        return self::decode($response);
    }

    /** @return array{status: int, body: mixed, headers: array<string, string>} */
    public static function decode(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }

        $raw = (string) $response->getBody();

        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode($raw, true) ?? $raw,
            'headers' => $headers,
        ];
    }
}
