<?php

declare(strict_types=1);

namespace Strapi\Openapi\Tests\Fixtures;

/** Port of __tests__/fixtures/routes.ts. */
final class Routes
{
    /** @return list<array<string, mixed>> */
    public static function test(): array
    {
        return [
            ['info' => ['type' => 'content-api'], 'method' => 'GET', 'path' => '/api/test1', 'handler' => ''],
            ['info' => ['type' => 'admin'], 'method' => 'POST', 'path' => '/test2', 'handler' => ''],
            ['info' => ['type' => 'content-api'], 'method' => 'DELETE', 'path' => '/api/test3', 'handler' => ''],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function foobar(): array
    {
        return [
            ['info' => ['type' => 'content-api'], 'method' => 'PUT', 'path' => '/api/foo', 'handler' => ''],
            ['info' => ['type' => 'admin'], 'method' => 'PATCH', 'path' => '/bar', 'handler' => ''],
            ['info' => ['type' => 'admin'], 'method' => 'HEAD', 'path' => '/baz', 'handler' => ''],
        ];
    }
}
