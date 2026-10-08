<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Services\Mcp;

use Strapi\Core\Services\Mcp\Authentication;
use Strapi\Core\Services\Mcp\Handlers\HandlePost;
use Strapi\Core\Services\Mcp\Internal\McpConfiguration;
use Strapi\Core\Services\Mcp\Internal\McpServerFactory;
use Strapi\Core\Services\Mcp\Mcp;
use Strapi\Core\Services\Mcp\Middleware\OauthDiscoveryFallback;
use Strapi\Core\Services\Mcp\Routes;
use Strapi\Core\Services\Mcp\Sdk\StreamableHttpTransport;
use Strapi\Core\Services\Mcp\Utils\JsonRpcErrors;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\UnauthorizedError;
use Strapi\Utils\Zod\Z as z;
use Strapi\Utils\Zod\ZodType;

/**
 * Ports of __tests__/{authentication,routes,service-integration}.test.ts,
 * handlers/__tests__/handlePost.test.ts and middleware/__tests__/oauthDiscoveryFallback.test.ts,
 * plus the stateless Streamable HTTP transport (sdk/).
 */
final class HttpTest extends McpTestCase
{
    private const HEADERS = ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json'];

    /** @param array<string, mixed>|\Closure(string): array<string, mixed> $result */
    private function strapiWithTokenService(array|\Closure $result): Strapi
    {
        $strapi = $this->createStrapi();
        $service = new class ($result) {
            /** @var list<string> */
            public array $tokens = [];

            /** @param array<string, mixed>|\Closure(string): array<string, mixed> $result */
            public function __construct(private array|\Closure $result)
            {
            }

            /** @return array<string, mixed> */
            public function authenticateAdminToken(string $token): array
            {
                $this->tokens[] = $token;

                return $this->result instanceof \Closure ? ($this->result)($token) : $this->result;
            }
        };
        $strapi->get('services')->set('admin::api-token-admin', static fn (): object => $service);

        return $strapi;
    }

    private function authenticated(): Strapi
    {
        return $this->strapiWithTokenService(['authenticated' => true, 'credentials' => ['id' => 7], 'user' => ['id' => 42, 'email' => 'x'], 'ability' => self::ability()]);
    }

    public function testAuthentication(): void
    {
        $strapi = $this->strapiWithTokenService(['authenticated' => false, 'error' => new UnauthorizedError('Token expired')]);
        $authenticator = Authentication::createMcpAdminTokenAuthenticator($strapi);

        self::assertSame(['authenticated' => false, 'reason' => 'missing_token'], $authenticator->authenticate(self::context()));
        self::assertSame(['authenticated' => false, 'reason' => 'missing_token'], $authenticator->authenticate(self::context(headers: ['Authorization' => 'Basic abc'])));
        self::assertSame(['authenticated' => false, 'reason' => 'missing_token'], $authenticator->authenticate(self::context(headers: ['Authorization' => 'Bearer a b'])));

        $result = $authenticator->authenticate(self::context(headers: ['Authorization' => 'Bearer token-1']));
        self::assertSame('invalid_token', $result['reason'] ?? null);
        self::assertInstanceOf(UnauthorizedError::class, $result['error'] ?? null);

        $ability = self::ability();
        $strapi = $this->strapiWithTokenService(['authenticated' => true, 'credentials' => ['id' => 7, 'accessKey' => 'secret'], 'user' => ['id' => 42, 'email' => 'x'], 'ability' => $ability]);
        self::assertSame(
            ['authenticated' => true, 'credentials' => ['id' => 7], 'user' => ['id' => 42], 'ability' => $ability],
            Authentication::createMcpAdminTokenAuthenticator($strapi)->authenticate(self::context(headers: ['authorization' => 'bearer token-2'])),
        );
    }

    public function testRoutes(): void
    {
        $config = new McpConfiguration($this->createStrapi());
        $handlePost = static function (Context $ctx): void {
        };
        $routes = Routes::createMcpRoutes($config, ['handlePost' => $handlePost]);

        self::assertSame(['POST', 'GET', 'DELETE', 'PUT', 'PATCH'], array_column($routes, 'method'));
        self::assertSame(['/mcp'], array_values(array_unique(array_column($routes, 'path'))));
        self::assertSame([['auth' => false]], array_values(array_unique(array_column($routes, 'config'), SORT_REGULAR)));
        self::assertSame($handlePost, $routes[0]['handler']);
        self::assertEquals($routes[1]['handler'], $routes[4]['handler']);
        self::assertNotContains('/.well-known/oauth-authorization-server', array_column($routes, 'path'));

        $ctx = self::context('GET');
        ($routes[1]['handler'])($ctx);
        self::assertSame(405, $ctx->status());
        self::assertSame('POST', $ctx->responseHeader('Allow'));
        self::assertSame(['jsonrpc' => '2.0', 'error' => ['code' => -32601, 'message' => 'Method not allowed'], 'id' => null], json_decode((string) $ctx->body(), true));
    }

    public function testSendJsonRpcErrorKeepsAWrittenResponse(): void
    {
        $ctx = self::context();
        $ctx->setStatus(200);
        $ctx->setBody('already');
        \Strapi\Core\Services\Mcp\Utils\SendJsonRpcError::sendJsonRpcError($ctx, 'INTERNAL_ERROR');
        self::assertSame('already', $ctx->body());

        $ctx = self::context();
        \Strapi\Core\Services\Mcp\Utils\SendJsonRpcError::sendJsonRpcError($ctx, 'AUTHENTICATION_REQUIRED', 'Custom');
        self::assertSame(401, $ctx->status());
        self::assertSame('application/json', $ctx->responseHeader('Content-Type'));
        self::assertSame(['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'Custom'], 'id' => null], json_decode((string) $ctx->body(), true));
        self::assertSame(500, JsonRpcErrors::JSON_RPC_ERRORS['INTERNAL_ERROR']['httpStatus']);
    }

    public function testOauthDiscoveryFallback(): void
    {
        $middleware = OauthDiscoveryFallback::createOAuthDiscoveryFallbackMiddleware();
        $run = static function (string $method, string $path, int $status) use ($middleware): Context {
            $ctx = self::context($method, $path);
            $called = false;
            $middleware($ctx, static function () use ($ctx, $status, &$called): void {
                $called = true;
                $ctx->setStatus($status);
            });
            self::assertTrue($called, 'always calls next()');

            return $ctx;
        };

        foreach ([['GET', '/.well-known/oauth-authorization-server', 404], ['GET', '/.well-known/openid-configuration', 405], ['POST', '/register', 404]] as [$method, $path, $status]) {
            $ctx = $run($method, $path, $status);
            self::assertSame(404, $ctx->status());
            self::assertSame(['error' => 'not_found', 'error_description' => 'OAuth is not supported'], $ctx->body());
        }

        self::assertNull($run('POST', '/register', 200)->body(), 'a user route wins');
        self::assertNull($run('GET', '/some-other-path', 404)->body());
        self::assertNull($run('PUT', '/.well-known/oauth-authorization-server', 404)->body());
    }

    /** @param array<string, mixed>|list<array<string, mixed>>|null $body */
    private function post(Strapi $strapi, mixed $body, array $headers = self::HEADERS, ?Mcp $service = null): Context
    {
        $service ??= Mcp::createMcpService($strapi);
        $handler = HandlePost::createPostHandler([
            'strapi' => $strapi,
            'authenticationStrategy' => Authentication::createMcpAdminTokenAuthenticator($strapi),
            'config' => $service->config(),
            'createServerWithRegistries' => McpServerFactory::createMcpServerWithRegistries(...),
            'capabilityDefinitions' => $service->capabilityDefinitions(),
        ]);
        $ctx = self::context('POST', '/mcp', [...$headers, 'Authorization' => 'Bearer key'], $body);
        $handler($ctx);

        return $ctx;
    }

    public function testHandlePostAuthentication(): void
    {
        $strapi = $this->strapiWithTokenService(['authenticated' => false]);
        $ctx = $this->post($strapi, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        self::assertSame(401, $ctx->status());
        self::assertSame(['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'Authentication required'], 'id' => null], json_decode((string) $ctx->body(), true));
        self::assertSame([['event' => 'didNotAuthenticateMcpRequest', 'payload' => ['eventProperties' => ['errorClass' => 'invalid_token']]]], $this->telemetry());
    }

    public function testHandlePostAnswersOverTheStatelessTransport(): void
    {
        $strapi = $this->authenticated();
        $service = Mcp::createMcpService($strapi);
        $service->registerTool(['name' => 'echo', 'title' => 'Echo', 'description' => 'Echo', 'auth' => ['policies' => [['action' => 'test.read']]],
            'resolveInputSchema' => static fn (): ZodType => z::object(['text' => z::string()]),
            'resolveOutputSchema' => static fn (): ZodType => z::object(['text' => z::string()]),
            'createHandler' => static fn () => static fn (array $p): array => ['content' => [], 'structuredContent' => ['text' => $p['args']['text']]],
        ]);

        $ctx = $this->post($strapi, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']]], service: $service);
        self::assertSame(200, $ctx->status());
        self::assertSame('text/event-stream', $ctx->responseHeader('Content-Type'));
        self::assertNull($ctx->responseHeader('mcp-session-id'));
        self::assertStringStartsWith("event: message\ndata: {", (string) $ctx->body());
        self::assertSame(['jsonrpc' => '2.0', 'id' => 1], array_intersect_key(self::sseResponse($ctx) ?? [], ['jsonrpc' => 1, 'id' => 1]));
        self::assertSame(['id' => 42], $ctx->state()->get('user'));
        self::assertSame('mcp', $ctx->state()->get('auditSource'));

        $ctx = $this->post($strapi, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], service: $service);
        self::assertSame(202, $ctx->status());
        self::assertNull($ctx->body());

        $ctx = $this->post($strapi, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], service: $service);
        self::assertSame(['echo'], array_column(self::sseResponse($ctx)['result']['tools'] ?? [], 'name'), 'the dev-mode log tool is not listed outside dev mode');

        $ctx = $this->post($strapi, [['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['text' => 'hi']]], ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping']], service: $service);
        self::assertSame(2, substr_count((string) $ctx->body(), 'event: message'));
        self::assertStringContainsString('"structuredContent":{"text":"hi"}', (string) $ctx->body());

        $events = array_column($this->telemetry(), 'event');
        self::assertContains('didUseMcpServer', $events);
        self::assertContains('didExecuteMcpCapability', $events);
    }

    public function testTransportRejections(): void
    {
        $strapi = $this->authenticated();
        $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];
        $error = static fn (Context $ctx): array => json_decode((string) $ctx->body(), true)['error'];

        $ctx = $this->post($strapi, $request, ['Accept' => 'application/json', 'Content-Type' => 'application/json']);
        self::assertSame(406, $ctx->status());
        self::assertSame('Not Acceptable: Client must accept both application/json and text/event-stream', $error($ctx)['message']);

        $ctx = $this->post($strapi, $request, ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'text/plain']);
        self::assertSame(415, $ctx->status());

        $ctx = $this->post($strapi, ['nope' => true]);
        self::assertSame(400, $ctx->status());
        self::assertSame(['code' => -32700, 'message' => 'Parse error: Invalid JSON-RPC message'], $error($ctx));

        $ctx = $this->post($strapi, null);
        self::assertSame(400, $ctx->status(), 'no body: not a JSON-RPC message (upstream passes `ctx.request.body ?? null`)');

        $ctx = $this->post($strapi, [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'], $request]);
        self::assertSame(['code' => -32600, 'message' => 'Invalid Request: Only one initialization request is allowed'], $error($ctx));

        $ctx = $this->post($strapi, $request, [...self::HEADERS, 'Mcp-Protocol-Version' => '1999-01-01']);
        self::assertSame(400, $ctx->status());
        self::assertStringStartsWith('Bad Request: Unsupported protocol version: 1999-01-01', $error($ctx)['message']);
    }

    public function testHandlePostFailures(): void
    {
        $strapi = $this->authenticated();
        $service = Mcp::createMcpService($strapi);
        $handler = HandlePost::createPostHandler([
            'strapi' => $strapi,
            'authenticationStrategy' => Authentication::createMcpAdminTokenAuthenticator($strapi),
            'config' => $service->config(),
            'createServerWithRegistries' => McpServerFactory::createMcpServerWithRegistries(...),
            'capabilityDefinitions' => $service->capabilityDefinitions(),
            'createTransport' => static fn (): StreamableHttpTransport => throw new \RuntimeException('transport exploded'),
        ]);
        $ctx = self::context('POST', '/mcp', [...self::HEADERS, 'Authorization' => 'Bearer key'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $handler($ctx);

        self::assertSame(500, $ctx->status());
        self::assertSame(['code' => -32603, 'message' => 'Internal error'], json_decode((string) $ctx->body(), true)['error']);
        self::assertContains('[MCP] Error handling POST request', $this->logged('error'));
        self::assertContains(['event' => 'didNotHandleMcpRequest', 'payload' => ['eventProperties' => ['errorClass' => 'error']]], $this->telemetry());

        // an authentication strategy that throws: no "handle" failure telemetry
        $this->logs = [];
        $strapi = $this->strapiWithTokenService(static fn (): array => throw new \RuntimeException('db down'));
        $ctx = $this->post($strapi, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        self::assertSame(500, $ctx->status());
        self::assertNotContains('didNotHandleMcpRequest', array_column($this->telemetry(), 'event'));
    }

    public function testServiceLifecycle(): void
    {
        $strapi = $this->createStrapi();
        $strapi->config()->set('server.mcp.enabled', false);
        $service = Mcp::createMcpService($strapi);
        self::assertSame(1, $service->capabilityDefinitions()['tools']->size(), 'the log tool');
        $service->start();
        self::assertFalse($service->isRunning());
        self::assertContains('[MCP] Server is disabled', $this->logged('debug'));
        self::assertNotContains('didStartMcpServer', array_column($this->telemetry(), 'event'));

        $strapi->config()->set('server.mcp.enabled', true);
        $strapi->config()->set('server.url', 'http://localhost:1337');
        $service->start();
        self::assertTrue($service->isRunning());
        self::assertContains('[MCP] Server available at http://localhost:1337/mcp', $this->logged('info'));
        self::assertContains(['event' => 'didStartMcpServer', 'payload' => ['eventProperties' => ['path' => '/mcp'], 'groupProperties' => ['numberOfTools' => 1, 'numberOfPrompts' => 0, 'numberOfResources' => 0]]], $this->telemetry());
        $paths = array_map(static fn (array $r): string => $r['method'] . ' ' . $r['path'], $strapi->server()->listRoutes());
        foreach (['POST /mcp', 'GET /mcp', 'DELETE /mcp', 'PUT /mcp', 'PATCH /mcp'] as $route) {
            self::assertContains($route, $paths);
        }

        foreach (['registerTool' => 'tools', 'registerPrompt' => 'prompts', 'registerResource' => 'resources'] as $method => $label) {
            try {
                $service->{$method}(['name' => 'late', 'devModeOnly' => true]);
                self::fail($method);
            } catch (\RuntimeException $e) {
                self::assertSame("[MCP] Cannot register {$label} after the MCP server has started.", $e->getMessage());
            }
        }

        try {
            $service->start();
            self::fail('start twice');
        } catch (\RuntimeException $e) {
            self::assertSame('[MCP] Server already started or starting (status: running)', $e->getMessage());
        }

        $service->stop();
        self::assertFalse($service->isRunning());
        self::assertContains('[MCP] Service stopped', $this->logged('info'));
    }
}
