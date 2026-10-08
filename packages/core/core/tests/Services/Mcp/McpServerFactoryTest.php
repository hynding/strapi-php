<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Services\Mcp;

use Strapi\Core\Services\Mcp\Internal\McpServerFactory;
use Strapi\Core\Services\Mcp\Tools\Log;
use Strapi\Utils\Zod\Z as z;
use Strapi\Utils\Zod\ZodType;

/**
 * Ports of internal/__tests__/{McpServerFactory,McpToolDiscovery}.test.ts and
 * __tests__/capability-results.test.ts (upstream connects an in-memory SDK client; here the
 * JSON-RPC messages go to McpServer::handleMessage()).
 */
final class McpServerFactoryTest extends McpTestCase
{
    private const AUTH = ['policies' => [['action' => 'test.read']]];

    /** @return list<array<string, mixed>> */
    private static function everyContentVariant(): array
    {
        return [
            ['type' => 'text', 'text' => 'text variant', 'annotations' => ['audience' => ['user'], 'priority' => 0.5, 'lastModified' => '2026-09-02T10:00:00Z'], '_meta' => ['vendor.example/text' => 'kept']],
            ['type' => 'image', 'data' => 'aGVsbG8=', 'mimeType' => 'image/png', 'annotations' => ['audience' => ['assistant']], '_meta' => ['vendor.example/image' => 'kept']],
            ['type' => 'audio', 'data' => 'aGVsbG8=', 'mimeType' => 'audio/wav', '_meta' => ['vendor.example/audio' => 'kept']],
            ['type' => 'resource_link', 'name' => 'linked-resource', 'uri' => 'strapi://app/linked', 'title' => 'Linked resource', 'description' => 'Every listing attribute on a link', 'mimeType' => 'application/json', 'size' => 42, 'icons' => [['src' => 'strapi://icon.png', 'mimeType' => 'image/png', 'sizes' => ['16x16'], 'theme' => 'light']], 'annotations' => ['priority' => 1], '_meta' => ['vendor.example/link' => 'kept']],
            ['type' => 'resource', 'resource' => ['uri' => 'strapi://app/embedded', 'mimeType' => 'application/octet-stream', 'blob' => 'aGVsbG8=', '_meta' => ['vendor.example/embedded-resource' => 'kept']], 'annotations' => ['audience' => ['user']], '_meta' => ['vendor.example/embedded' => 'kept']],
        ];
    }

    public function testCreatesTheServerWithRegistriesAndSyncsCapabilities(): void
    {
        $strapi = $this->createStrapi();
        $definitions = self::definitions();
        $definitions['tools']->define(Log::logToolDefinition());
        $definitions['tools']->define(['name' => 'auth-tool', 'title' => 'A', 'description' => 'A', 'auth' => self::AUTH, 'resolveOutputSchema' => static fn (): ZodType => z::object([]), 'createHandler' => static fn () => static fn (): array => ['content' => [], 'structuredContent' => []]]);
        $definitions['tools']->define(['name' => 'subject-tool', 'title' => 'S', 'description' => 'S', 'auth' => ['policies' => [['action' => 'test.read', 'subject' => 'api::x.x']]], 'resolveOutputSchema' => static fn (): ZodType => z::object([]), 'createHandler' => static fn () => static fn (): array => ['content' => [], 'structuredContent' => []]]);

        $result = McpServerFactory::createMcpServerWithRegistries(['strapi' => $strapi, 'definitions' => $definitions, 'isDevMode' => true, 'ability' => self::ability(), 'user' => ['id' => 1]]);
        self::assertSame('enabled', $result['registries']['tools']->status('log'), 'devModeOnly in dev mode');
        self::assertSame('enabled', $result['registries']['tools']->status('auth-tool'));
        self::assertSame('enabled', $result['registries']['tools']->status('subject-tool'), 'the subject is checked (`can(test.read, api::x.x)`)');

        $init = self::rpc($result['mcpServer'], 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'c', 'version' => '1']]);
        self::assertSame(['protocolVersion' => '2025-06-18', 'capabilities' => ['logging' => [], 'tools' => ['listChanged' => true]], 'serverInfo' => ['name' => 'strapi-mcp-server', 'version' => '1.0.0']], $init['result']);
        self::assertSame('2025-11-25', self::rpc($result['mcpServer'], 'initialize', ['protocolVersion' => '1999-01-01'])['result']['protocolVersion']);

        $result = McpServerFactory::createMcpServerWithRegistries(['strapi' => $strapi, 'definitions' => $definitions, 'isDevMode' => false, 'ability' => self::ability(['other']), 'user' => ['id' => 1]]);
        self::assertSame('disabled', $result['registries']['tools']->status('log'));
        self::assertSame('disabled', $result['registries']['tools']->status('auth-tool'), 'denied action');
        self::assertSame([], self::rpc($result['mcpServer'], 'tools/list')['result']['tools']);
        self::assertSame(['code' => -32602, 'message' => 'Tool auth-tool disabled'], self::rpc($result['mcpServer'], 'tools/call', ['name' => 'auth-tool'])['error']);
        self::assertSame(['code' => -32602, 'message' => 'Tool nope not found'], self::rpc($result['mcpServer'], 'tools/call', ['name' => 'nope'])['error']);

        // empty definitions: logging only, no tools methods
        $empty = $this->server($strapi, self::definitions());
        self::assertSame(['logging' => []], self::rpc($empty, 'initialize')['result']['capabilities']);
        self::assertSame(['code' => -32601, 'message' => 'Method not found'], self::rpc($empty, 'tools/list')['error']);
        self::assertSame([], self::rpc($empty, 'ping')['result']);
        self::assertNull($empty->handleMessage(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
    }

    public function testAdaptsToolCallbacksToTheStrapiHandlerShape(): void
    {
        $strapi = $this->createStrapi();
        $definitions = self::definitions();
        $calls = new \ArrayObject();
        $definitions['tools']->define([
            'name' => 'input-tool', 'title' => 'I', 'description' => 'I', 'auth' => self::AUTH,
            'resolveInputSchema' => static fn (): ZodType => z::object(['name' => z::string()]),
            'resolveOutputSchema' => static fn (): ZodType => z::object(['greeting' => z::string()]),
            'createHandler' => static function ($strapi, array $context) use ($calls): \Closure {
                $calls[] = ['context' => $context];

                return static function (array $params) use ($calls): array {
                    $calls[] = $params;

                    return ['content' => [], 'structuredContent' => ['greeting' => 'Hello ' . $params['args']['name']]];
                };
            },
        ]);
        $definitions['tools']->define([
            'name' => 'no-input-tool', 'title' => 'N', 'description' => 'N', 'auth' => self::AUTH,
            'resolveOutputSchema' => static fn (): ZodType => z::object(['ok' => z::boolean()]),
            'createHandler' => static fn () => static function (array $params) use ($calls): array {
                $calls[] = $params;

                return ['content' => [], 'structuredContent' => ['ok' => true]];
            },
        ]);
        $server = $this->server($strapi, $definitions);

        self::assertSame(1, $calls[0]['context']['user']['id']);
        self::assertTrue($calls[0]['context']['userAbility']->can('test.read'));

        $tools = self::rpc($server, 'tools/list')['result']['tools'];
        self::assertSame(['input-tool', 'no-input-tool'], array_column($tools, 'name'));
        self::assertSame(['type' => 'object', 'properties' => []], $tools[1]['inputSchema'], 'no resolveInputSchema: the empty object schema');

        $result = self::rpc($server, 'tools/call', ['name' => 'input-tool', 'arguments' => ['name' => 'Ada'], '_meta' => ['progressToken' => 't']], 7);
        self::assertSame(['content' => [], 'structuredContent' => ['greeting' => 'Hello Ada']], $result['result']);
        self::assertSame(['name' => 'Ada'], $calls[1]['args']);
        self::assertSame(['requestId' => 7, '_meta' => ['progressToken' => 't'], 'requestInfo' => ['headers' => ['x-test' => 'yes'], 'url' => 'http://localhost/mcp']], $calls[1]['extra']);

        self::assertSame(['ok' => true], self::rpc($server, 'tools/call', ['name' => 'no-input-tool'])['result']['structuredContent']);
        self::assertArrayNotHasKey('args', $calls[2]);
    }

    public function testPromptsAndResourcesGetTheSameHandlerContext(): void
    {
        $strapi = $this->createStrapi();
        $definitions = self::definitions();
        $seen = new \ArrayObject();
        $definitions['prompts']->define(['name' => 'plain', 'title' => 'P', 'description' => 'P', 'auth' => self::AUTH, 'createHandler' => static fn () => static function (array $extra) use ($seen): array {
            $seen['plain'] = $extra;

            return ['messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'hi']]]];
        }]);
        $definitions['prompts']->define(['name' => 'args', 'title' => 'A', 'description' => 'A', 'auth' => self::AUTH, 'argsSchema' => z::object(['topic' => z::string()->describe('The topic')]), 'createHandler' => static fn () => static function (array $args, array $extra) use ($seen): array {
            $seen['args'] = [$args, $extra];

            return ['messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $args['topic']]]]];
        }]);
        $definitions['resources']->define(['name' => 'info', 'uri' => 'strapi://app/info', 'metadata' => ['mimeType' => 'text/plain'], 'auth' => self::AUTH, 'createHandler' => static fn () => static function (string $uri, array $extra) use ($seen): array {
            $seen['resource'] = [$uri, $extra];

            return ['contents' => [['uri' => $uri, 'text' => 'ok']]];
        }]);
        $server = $this->server($strapi, $definitions);

        self::assertSame([['name' => 'plain', 'title' => 'P', 'description' => 'P'], ['name' => 'args', 'title' => 'A', 'description' => 'A', 'arguments' => [['name' => 'topic', 'description' => 'The topic', 'required' => true]]]], self::rpc($server, 'prompts/list')['result']['prompts']);
        self::assertSame('hi', self::rpc($server, 'prompts/get', ['name' => 'plain'])['result']['messages'][0]['content']['text']);
        self::assertSame(1, $seen['plain']['requestId']);
        self::assertSame('maths', self::rpc($server, 'prompts/get', ['name' => 'args', 'arguments' => ['topic' => 'maths']], 2)['result']['messages'][0]['content']['text']);
        self::assertSame(['topic' => 'maths'], $seen['args'][0]);
        self::assertSame(2, $seen['args'][1]['requestId']);
        self::assertSame(-32602, self::rpc($server, 'prompts/get', ['name' => 'args', 'arguments' => []])['error']['code']);

        self::assertSame([['uri' => 'strapi://app/info', 'name' => 'info', 'mimeType' => 'text/plain']], self::rpc($server, 'resources/list')['result']['resources']);
        self::assertSame([['uri' => 'strapi://app/info', 'text' => 'ok']], self::rpc($server, 'resources/read', ['uri' => 'strapi://app/info'], 3)['result']['contents']);
        self::assertSame('strapi://app/info', $seen['resource'][0]);
        self::assertSame(3, $seen['resource'][1]['requestId']);
    }

    public function testStrictClientDiscoveryAndExecution(): void
    {
        $strapi = $this->createStrapi();
        $calls = new \ArrayObject();
        $recursiveNode = null;
        $recursiveNode = z::lazy(static function () use (&$recursiveNode): ZodType {
            return z::object(['label' => z::string(), 'child' => $recursiveNode->optional()])->strict();
        });
        $definitions = self::definitions();
        $definitions['tools']->define([
            'name' => 'representative-tool', 'title' => 'Representative tool', 'description' => 'Exercises dialect-sensitive schema constructs', 'auth' => self::AUTH,
            'resolveInputSchema' => static fn (): ZodType => z::object(['pair' => z::tuple([z::string(), z::number()]), 'node' => $recursiveNode]),
            'resolveOutputSchema' => static fn (): ZodType => z::object(['pair' => z::tuple([z::string(), z::number()]), 'attempts' => z::number()->default(1)])->loose(),
            'createHandler' => static fn () => static function (array $params) use ($calls): array {
                $calls[] = 1;

                return ['content' => [], 'structuredContent' => ['pair' => $params['args']['pair'], 'attempts' => 1]];
            },
        ]);
        $definitions['tools']->define([
            'name' => 'failing-tool', 'title' => 'Failing tool', 'description' => 'Exercises safe handler failures', 'auth' => self::AUTH,
            'resolveInputSchema' => static fn (): ZodType => z::object([]),
            'resolveOutputSchema' => static fn (): ZodType => z::object(['ok' => z::boolean()]),
            'createHandler' => static fn () => static fn (): array => throw new \RuntimeException('handler failed'),
        ]);
        $definitions['tools']->define([
            'name' => 'registration-failure', 'title' => 'Registration failure', 'description' => 'Exercises registration fault isolation', 'auth' => self::AUTH,
            'resolveInputSchema' => static fn (): ZodType => z::object([]),
            'resolveOutputSchema' => static fn (): ZodType => throw new \RuntimeException('schema resolution failed'),
            'createHandler' => static fn () => static fn (): array => ['content' => [], 'structuredContent' => ['ok' => true]],
        ]);
        $server = $this->server($strapi, $definitions);

        $tools = self::rpc($server, 'tools/list')['result']['tools'];
        self::assertSame(['representative-tool', 'failing-tool'], array_column($tools, 'name'));
        $input = $tools[0]['inputSchema'];
        self::assertSame('https://json-schema.org/draft/2020-12/schema', $input['$schema']);
        self::assertSame(['type' => 'array', 'prefixItems' => [['type' => 'string'], ['type' => 'number']]], array_intersect_key($input['properties']['pair'], ['type' => 1, 'prefixItems' => 1]));
        self::assertArrayHasKey('$defs', $input);
        self::assertStringContainsString('"$ref":"#\/$defs\/', (string) json_encode($input));
        self::assertStringNotContainsString('"definitions"', (string) json_encode($input));

        $ok = self::rpc($server, 'tools/call', ['name' => 'representative-tool', 'arguments' => ['pair' => ['value', 2], 'node' => ['label' => 'root', 'child' => ['label' => 'leaf']]]]);
        self::assertSame(['pair' => ['value', 2], 'attempts' => 1], $ok['result']['structuredContent']);
        self::assertCount(1, $calls);

        $invalid = self::rpc($server, 'tools/call', ['name' => 'representative-tool', 'arguments' => ['pair' => [2, 'value'], 'node' => ['label' => 'root']]]);
        self::assertTrue($invalid['result']['isError']);
        self::assertStringStartsWith('Input validation error: Invalid arguments for tool representative-tool: pair.0: ', $invalid['result']['content'][0]['text']);
        self::assertCount(1, $calls);

        $failed = self::rpc($server, 'tools/call', ['name' => 'failing-tool', 'arguments' => []]);
        self::assertSame(['content' => [['type' => 'text', 'text' => 'Tool "failing-tool" execution failed: handler failed']], 'isError' => true], $failed['result']);
        self::assertContains('[MCP] Failed to register tool "registration-failure" with MCP server: schema resolution failed', $this->logged('error'));
        self::assertNotSame([], $this->telemetry());
    }

    public function testOwnedCapabilityResultsReachTheClientUnchanged(): void
    {
        $strapi = $this->createStrapi();
        $definitions = self::definitions();
        $definitions['tools']->define(['name' => 'every-variant-tool', 'title' => 'T', 'description' => 'T', 'auth' => self::AUTH, 'resolveOutputSchema' => static fn (): ZodType => z::object(['ok' => z::boolean()]), 'createHandler' => static fn () => static fn (): array => ['content' => self::everyContentVariant(), 'structuredContent' => ['ok' => true]]]);
        $definitions['tools']->define(['name' => 'error-tool', 'title' => 'E', 'description' => 'E', 'auth' => self::AUTH, 'resolveOutputSchema' => static fn (): ZodType => z::object(['ok' => z::boolean()]), 'createHandler' => static fn () => static fn (): array => ['content' => [['type' => 'text', 'text' => 'it failed']], 'isError' => true]]);
        $definitions['tools']->define(['name' => 'invalid-output-tool', 'title' => 'O', 'description' => 'O', 'auth' => self::AUTH, 'resolveOutputSchema' => static fn (): ZodType => z::object(['ok' => z::boolean()]), 'createHandler' => static fn () => static fn (): array => ['content' => [], 'structuredContent' => ['ok' => 'not a boolean']]]);
        $definitions['prompts']->define(['name' => 'every-variant-prompt', 'title' => 'P', 'description' => 'P', 'auth' => self::AUTH, 'createHandler' => static fn () => static fn (): array => [
            'description' => 'Owned prompt result',
            'messages' => array_map(static fn (array $content, int $i): array => ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => $content], self::everyContentVariant(), array_keys(self::everyContentVariant())),
            '_meta' => ['vendor.example/prompt' => 'kept'],
            'vendor.example/top-level' => ['nested' => true],
        ]]);
        $listing = ['title' => 'App info', 'description' => 'Metadata about the app', 'mimeType' => 'application/json', 'size' => 128, 'icons' => [['src' => 'strapi://icon.svg', 'mimeType' => 'image/svg+xml', 'theme' => 'dark']], 'annotations' => ['audience' => ['user'], 'priority' => 0.75], '_meta' => ['vendor.example/listing' => 'kept']];
        $definitions['resources']->define(['name' => 'app-info', 'uri' => 'strapi://app/info', 'metadata' => $listing, 'auth' => self::AUTH, 'createHandler' => static fn () => static fn (string $uri): array => [
            'contents' => [['uri' => $uri, 'mimeType' => 'text/plain', 'text' => 'text contents'], ['uri' => "{$uri}/binary", 'mimeType' => 'application/octet-stream', 'blob' => 'aGVsbG8=', '_meta' => ['vendor.example/blob' => 'kept']]],
            '_meta' => ['vendor.example/read' => 'kept'],
            'vendor.example/top-level' => 'kept',
        ]]);
        $server = $this->server($strapi, $definitions);

        $result = self::rpc($server, 'tools/call', ['name' => 'every-variant-tool', 'arguments' => []])['result'];
        self::assertSame(['ok' => true], $result['structuredContent']);
        self::assertArrayNotHasKey('isError', $result);
        self::assertEquals(json_decode((string) json_encode(self::everyContentVariant()), true), $result['content']);

        self::assertSame(['content' => [['type' => 'text', 'text' => 'it failed']], 'isError' => true], self::rpc($server, 'tools/call', ['name' => 'error-tool', 'arguments' => []])['result']);
        self::assertTrue(self::rpc($server, 'tools/call', ['name' => 'invalid-output-tool', 'arguments' => []])['result']['isError']);

        $prompt = self::rpc($server, 'prompts/get', ['name' => 'every-variant-prompt'])['result'];
        self::assertSame('Owned prompt result', $prompt['description']);
        self::assertCount(5, $prompt['messages']);
        self::assertSame('assistant', $prompt['messages'][1]['role']);
        self::assertSame(['vendor.example/prompt' => 'kept'], $prompt['_meta']);
        self::assertSame(['nested' => true], $prompt['vendor.example/top-level']);

        $advertised = self::rpc($server, 'resources/list')['result']['resources'];
        self::assertSame(['uri' => 'strapi://app/info', 'name' => 'app-info', ...$listing], $advertised[0]);
        $read = self::rpc($server, 'resources/read', ['uri' => 'strapi://app/info'])['result'];
        self::assertSame([
            ['uri' => 'strapi://app/info', 'mimeType' => 'text/plain', 'text' => 'text contents'],
            ['uri' => 'strapi://app/info/binary', 'mimeType' => 'application/octet-stream', 'blob' => 'aGVsbG8=', '_meta' => ['vendor.example/blob' => 'kept']],
        ], $read['contents']);
        self::assertSame(['vendor.example/read' => 'kept'], $read['_meta']);
        self::assertSame('kept', $read['vendor.example/top-level']);
    }
}
