<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Middlewares;

use Nyholm\Psr7\Factory\Psr17Factory;
use Strapi\Core\Middlewares\Body;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Tests\BootedAppTestCase;
use Strapi\Utils\EmptyObject;

/** strapi::body (koa-body): JSON `{}` and `[]` stay apart in the parsed body (PHP port, see EmptyObject). */
final class BodyTest extends BootedAppTestCase
{
    private static function parse(string $contentType, string $body): Context
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'http://localhost/api/tests')
            ->withHeader('Content-Type', $contentType)
            ->withBody($factory->createStream($body));
        $ctx = new Context($request);
        $middleware = (new Body())([], self::strapi());
        $middleware($ctx, static function (): void {
        });

        return $ctx;
    }

    public function testJsonEmptyObjectsAreMarked(): void
    {
        $ctx = self::parse('application/json', '{"data":{"comp":{},"list":[],"json":{"a":{}}}}');

        $body = $ctx->requestBody(true);
        self::assertIsArray($body);
        self::assertInstanceOf(EmptyObject::class, $body['data']['comp']);
        self::assertSame([], $body['data']['list']);
        self::assertInstanceOf(EmptyObject::class, $body['data']['json']['a']);

        // readers that do not ask get `json_decode($json, true)`
        self::assertSame(['data' => ['comp' => [], 'list' => [], 'json' => ['a' => []]]], $ctx->requestBody());
    }

    public function testTopLevelEmptyObject(): void
    {
        $ctx = self::parse('application/json', '{}');

        self::assertInstanceOf(EmptyObject::class, $ctx->requestBody(true));
        self::assertSame([], $ctx->requestBody());
    }

    public function testMultipartJsonFields(): void
    {
        $boundary = 'XyZ';
        $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"fileInfo\"\r\n\r\n{\"caption\":\"c\",\"meta\":{}}\r\n--{$boundary}--\r\n";
        $ctx = self::parse("multipart/form-data; boundary={$boundary}", $body);

        $parsed = $ctx->requestBody(true);
        self::assertIsArray($parsed);
        self::assertInstanceOf(EmptyObject::class, $parsed['fileInfo']['meta']);
        self::assertSame(['fileInfo' => ['caption' => 'c', 'meta' => []]], $ctx->requestBody());
    }
}
