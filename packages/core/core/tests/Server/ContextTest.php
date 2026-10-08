<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Server;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Strapi\Core\Services\Server\Context;
use Strapi\Utils\EmptyObject;
use Strapi\Utils\Errors\HttpError;

/** Koa semantics of the request context. */
final class ContextTest extends TestCase
{
    private static function ctx(string $method = 'GET', string $uri = 'http://localhost/api/test?x=1'): Context
    {
        return new Context((new Psr17Factory())->createServerRequest($method, $uri));
    }

    public function testImplicitStatusIs404UntilBodyOrStatusIsSet(): void
    {
        $ctx = self::ctx();
        self::assertSame(404, $ctx->status());
        self::assertFalse($ctx->hasExplicitStatus());

        $ctx->setBody(['a' => 1]);
        self::assertSame(200, $ctx->status());
        self::assertTrue($ctx->hasExplicitStatus());
    }

    public function testNullBodyBecomes204(): void
    {
        $ctx = self::ctx();
        $ctx->setBody(null);
        self::assertSame(204, $ctx->status());

        $ctx = self::ctx();
        $ctx->setStatus(201);
        $ctx->setBody(null);
        self::assertSame(204, $ctx->status(), 'Koa: a null body on a 2xx status is a 204');
    }

    public function testKoaErrorHelpersWriteTheEnvelope(): void
    {
        $ctx = self::ctx();
        $ctx->notFound();
        self::assertSame(404, $ctx->status());
        // details defaults to an empty object: `{}` on the wire
        self::assertSame('{"data":null,"error":{"status":404,"name":"NotFoundError","message":"Not Found","details":{}}}', json_encode($ctx->body()));

        $ctx = self::ctx();
        $ctx->badRequest('Bad things', ['field' => 'x']);
        self::assertSame(400, $ctx->status());
        self::assertSame('Bad things', $ctx->body()['error']['message']);
        self::assertSame(['field' => 'x'], $ctx->body()['error']['details']);

        $ctx = self::ctx();
        $ctx->forbidden();
        self::assertSame('ForbiddenError', $ctx->body()['error']['name']);
    }

    public function testThrowRaisesAnHttpError(): void
    {
        $ctx = self::ctx();
        try {
            $ctx->throw(404, 'Nope');
            self::fail('expected an HttpError');
        } catch (HttpError $e) {
            self::assertSame(404, $e->status);
            self::assertSame('Nope', $e->getMessage());
        }
    }

    public function testRequestAccessorsAndResponse(): void
    {
        $ctx = self::ctx('POST', 'http://localhost:1337/api/test?a=1&b[c]=2');
        self::assertSame('POST', $ctx->method());
        self::assertSame('/api/test', $ctx->path());
        self::assertSame('a=1&b%5Bc%5D=2', $ctx->querystring());
        self::assertSame(['a' => '1', 'b' => ['c' => '2']], $ctx->query());

        $ctx->setHeader('X-Test', 'yes');
        $ctx->setStatus(201);
        $ctx->setBody(['ok' => true, 'f' => 1.0]);

        $response = $ctx->toResponse();
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('yes', $response->getHeaderLine('X-Test'));
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"ok":true,"f":1.0}', (string) $response->getBody());
    }

    public function testRequestBodyKeepsEmptyObjectsOnlyWhenAsked(): void
    {
        $ctx = self::ctx('POST');
        $ctx->setRequestBody(['data' => ['comp' => new EmptyObject(), 'list' => []]]);

        self::assertSame(['data' => ['comp' => [], 'list' => []]], $ctx->requestBody());
        $marked = $ctx->requestBody(true);
        self::assertIsArray($marked);
        self::assertInstanceOf(EmptyObject::class, $marked['data']['comp']);
        self::assertSame([], $marked['data']['list']);

        $ctx->setRequestBody(['other' => 1]);
        self::assertSame(['other' => 1], $ctx->requestBody());
    }

    public function testRedirect(): void
    {
        $ctx = self::ctx();
        $ctx->redirect('/admin');
        $response = $ctx->toResponse();
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin', $response->getHeaderLine('Location'));
    }
}
