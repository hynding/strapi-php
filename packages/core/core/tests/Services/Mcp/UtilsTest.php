<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Services\Mcp;

use Strapi\Core\Services\Mcp\Metrics\Metrics;
use Strapi\Core\Services\Mcp\Metrics\NormalizeMcpCapability;
use Strapi\Core\Services\Mcp\Metrics\WrapCapabilityHandlerForMetrics;
use Strapi\Core\Services\Mcp\Sdk\RegisteredCapability;
use Strapi\Core\Services\Mcp\Tools\Log;
use Strapi\Core\Services\Mcp\Utils\CreateSafeCapabilityRegistration;
use Strapi\Core\Services\Mcp\Utils\SafeHandlerWrapper;
use Strapi\Core\Services\Mcp\Utils\ToSdkMcpCapabilityResult;
use Strapi\Core\Services\Mcp\Utils\WithTimeout;

/**
 * Ports of utils/__tests__/{safeHandlerWrapper,createSafeCapabilityRegistration,
 * toSdkMcpCapabilityResult,withTimeout}.test.ts and metrics/__tests__/*.test.ts, plus the log tool.
 */
final class UtilsTest extends McpTestCase
{
    protected function setUp(): void
    {
        Metrics::resetMcpMetricsStateForTests();
    }

    public function testWrapSafeHandler(): void
    {
        $strapi = $this->createStrapi();
        $options = ['strapi' => $strapi, 'capabilityType' => 'Tool', 'name' => 'my-tool', 'createErrorResult' => static fn (\Throwable $e, array $args): array => ['error' => $e->getMessage(), 'args' => $args]];

        $ok = SafeHandlerWrapper::wrapSafeHandler(static fn (int $a, int $b): array => ['sum' => $a + $b], $options);
        self::assertSame(['sum' => 3], $ok(1, 2));

        $failing = SafeHandlerWrapper::wrapSafeHandler(static fn (string $x): never => throw new \RuntimeException('boom'), $options);
        self::assertSame(['error' => 'boom', 'args' => ['in']], $failing('in'));
        self::assertSame('[MCP] Tool "my-tool" threw an error during execution: boom', $this->logs[0]['message']);
        self::assertSame('boom', $this->logs[0]['context']['error']);
        self::assertArrayHasKey('stack', $this->logs[0]['context']);
    }

    public function testCreateSafeCapabilityRegistration(): void
    {
        $strapi = $this->createStrapi();
        $registered = new RegisteredCapability();
        $base = [
            'strapi' => $strapi,
            'capabilityType' => 'Tool',
            'name' => 'cap',
            'createHandler' => static fn (): \Closure => static fn (): string => 'handled',
            'createFallbackHandler' => static fn (string $message): \Closure => static fn (): string => "fallback: {$message}",
            'createErrorResult' => static fn (\Throwable $e): string => "error: {$e->getMessage()}",
        ];

        $captured = new \ArrayObject();
        $result = CreateSafeCapabilityRegistration::createSafeCapabilityRegistration([...$base, 'registerWithSdk' => static function (\Closure $safe) use ($registered, $captured): RegisteredCapability {
            $captured['handler'] = $safe;

            return $registered;
        }]);
        self::assertSame($registered, $result);
        self::assertSame('handled', $captured['handler']());

        // Level 1: the factory throws → fallback handler, logged
        CreateSafeCapabilityRegistration::createSafeCapabilityRegistration([...$base, 'createHandler' => static fn (): never => throw new \RuntimeException('factory failed'), 'registerWithSdk' => static function (\Closure $safe) use ($registered, $captured): RegisteredCapability {
            $captured['handler'] = $safe;

            return $registered;
        }]);
        self::assertSame('fallback: factory failed', $captured['handler']());
        self::assertContains('[MCP] Tool "cap" handler factory threw during initialization: factory failed', $this->logged('error'));

        // Level 2: the handler throws at runtime
        CreateSafeCapabilityRegistration::createSafeCapabilityRegistration([...$base, 'createHandler' => static fn (): \Closure => static fn (): never => throw new \RuntimeException('runtime'), 'registerWithSdk' => static function (\Closure $safe) use ($registered, $captured): RegisteredCapability {
            $captured['handler'] = $safe;

            return $registered;
        }]);
        self::assertSame('error: runtime', $captured['handler']());

        // Level 3: the SDK registration throws
        $failed = CreateSafeCapabilityRegistration::createSafeCapabilityRegistration([...$base, 'registerWithSdk' => static fn (): never => throw new \RuntimeException('sdk rejected')]);
        self::assertSame(CreateSafeCapabilityRegistration::failedRegisteredCapability(), $failed);
        self::assertContains('[MCP] Failed to register tool "cap" with MCP server: sdk rejected', $this->logged('error'));
        $failed->enable();
        $failed->disable();
        $failed->remove();
        self::assertFalse($failed->enabled, 'cannot be enabled');
    }

    public function testToSdkResults(): void
    {
        $prompt = ToSdkMcpCapabilityResult::toSdkPromptResult(['messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'x', '_meta' => ['k' => 1], 'extra' => 'dropped']]], '_meta' => ['m' => 1], 'vendor/field' => true]);
        self::assertSame(['vendor/field' => true, 'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'x', '_meta' => ['k' => 1]]]], '_meta' => ['m' => 1]], $prompt);

        $read = ToSdkMcpCapabilityResult::toSdkResourceReadResult(['contents' => [['uri' => 'a', 'text' => 't', 'blob' => 'ignored']], 'vendor' => 'x']);
        self::assertSame(['vendor' => 'x', 'contents' => [['uri' => 'a', 'text' => 't']]], $read);

        self::assertSame(['title' => 'T', '_meta' => ['x' => 1]], ToSdkMcpCapabilityResult::toSdkResourceListingMetadata(['title' => 'T', 'other' => 1, '_meta' => ['x' => 1]]));
        self::assertSame(['content' => [], 'structuredContent' => ['ok' => true]], ToSdkMcpCapabilityResult::toSdkToolResult(['content' => [], 'structuredContent' => ['ok' => true]]));
        self::assertSame(['content' => [['type' => 'text', 'text' => 'e']], 'isError' => true], ToSdkMcpCapabilityResult::toSdkToolResult(['content' => [['type' => 'text', 'text' => 'e']], 'isError' => true]));

        $this->expectException(\InvalidArgumentException::class);
        ToSdkMcpCapabilityResult::toSdkContentBlock(['type' => 'video']);
    }

    public function testWithTimeout(): void
    {
        self::assertSame('value', WithTimeout::withTimeout(static fn (): string => 'value', 1000, 'op'));

        try {
            WithTimeout::withTimeout(static fn (): never => throw new \LogicException('original'), 1000, 'op');
            self::fail('rethrows');
        } catch (\LogicException $e) {
            self::assertSame('original', $e->getMessage());
        }

        try {
            WithTimeout::withTimeout(static function (): string {
                usleep(20000);

                return 'late';
            }, 5, 'slowOperation');
            self::fail('timed out');
        } catch (\RuntimeException $e) {
            self::assertSame("Operation 'slowOperation' timed out after 5ms", $e->getMessage());
            self::assertSame('timeout', Metrics::classifyMcpRequestFailure($e));
        }
        self::assertSame('error', Metrics::classifyMcpRequestFailure(new \RuntimeException('boom')));
        self::assertSame('error', Metrics::classifyMcpRequestFailure('not an error'));
    }

    public function testMetrics(): void
    {
        $strapi = $this->createStrapi();
        Metrics::sendDidStartMcpServer($strapi, ['path' => '/mcp', 'numberOfTools' => 3, 'numberOfPrompts' => 1, 'numberOfResources' => 0]);
        Metrics::sendDidUseMcpServer($strapi);
        Metrics::sendDidNotAuthenticateMcpRequest($strapi, 'missing_token');
        Metrics::sendDidNotHandleMcpRequest($strapi, 'timeout');
        $identity = NormalizeMcpCapability::normalizeMcpCapability('tool', 'create_article', ['source' => 'content-manager', 'name' => 'create']);
        Metrics::sendDidExecuteMcpCapability($strapi, $identity);
        Metrics::sendDidExecuteMcpCapability($strapi, $identity); // deduped within 24h
        Metrics::sendDidNotExecuteMcpCapability($strapi, $identity, 'execution_error'); // deduped independently
        Metrics::sendDidExecuteMcpCapability($strapi, ['type' => 'tool', 'source' => 'my-plugin', 'name' => 'create']); // same name, other source

        self::assertSame([
            ['event' => 'didStartMcpServer', 'payload' => ['eventProperties' => ['path' => '/mcp'], 'groupProperties' => ['numberOfTools' => 3, 'numberOfPrompts' => 1, 'numberOfResources' => 0]]],
            ['event' => 'didUseMcpServer', 'payload' => []],
            ['event' => 'didNotAuthenticateMcpRequest', 'payload' => ['eventProperties' => ['errorClass' => 'missing_token']]],
            ['event' => 'didNotHandleMcpRequest', 'payload' => ['eventProperties' => ['errorClass' => 'timeout']]],
            ['event' => 'didExecuteMcpCapability', 'payload' => ['eventProperties' => ['type' => 'tool', 'source' => 'content-manager', 'name' => 'create']]],
            ['event' => 'didNotExecuteMcpCapability', 'payload' => ['eventProperties' => ['type' => 'tool', 'source' => 'content-manager', 'name' => 'create', 'errorClass' => 'execution_error']]],
            ['event' => 'didExecuteMcpCapability', 'payload' => ['eventProperties' => ['type' => 'tool', 'source' => 'my-plugin', 'name' => 'create']]],
        ], $this->telemetry());
    }

    public function testNormalizeAndWrapForMetrics(): void
    {
        self::assertSame(['type' => 'tool', 'source' => 'unknown', 'name' => 'raw'], NormalizeMcpCapability::normalizeMcpCapability('tool', 'raw'));
        self::assertSame(['type' => 'tool', 'source' => 'my-plugin', 'name' => 'raw'], NormalizeMcpCapability::normalizeMcpCapability('tool', 'raw', ['source' => 'my-plugin']));
        self::assertSame(['type' => 'prompt', 'source' => 'unknown', 'name' => 'n'], NormalizeMcpCapability::normalizeMcpCapability('prompt', 'raw', ['name' => 'n']));

        $strapi = $this->createStrapi();
        $ok = WrapCapabilityHandlerForMetrics::wrapCapabilityHandlerForMetrics($strapi, 'tool', 'list_article', ['source' => 'content-manager', 'name' => 'list'], static fn (int $x): array => ['value' => $x]);
        self::assertSame(['value' => 2], $ok(2));
        $failing = WrapCapabilityHandlerForMetrics::wrapCapabilityHandlerForMetrics($strapi, 'resource', 'res', null, static fn (): array => ['isError' => true]);
        $failing();

        self::assertSame([
            ['event' => 'didExecuteMcpCapability', 'payload' => ['eventProperties' => ['type' => 'tool', 'source' => 'content-manager', 'name' => 'list']]],
            ['event' => 'didNotExecuteMcpCapability', 'payload' => ['eventProperties' => ['type' => 'resource', 'source' => 'unknown', 'name' => 'res', 'errorClass' => 'execution_error']]],
        ], $this->telemetry());
    }

    public function testLogTool(): void
    {
        $strapi = $this->createStrapi();
        $definition = Log::logToolDefinition();
        self::assertTrue($definition['devModeOnly']);
        self::assertSame(['source' => 'core', 'name' => 'log'], $definition['telemetry']);

        $handler = $definition['createHandler']($strapi);
        $args = $definition['resolveInputSchema']()->parse(['message' => "line1\nline2 \x1B[31mred\x1B[0m\x07"]);
        $result = $handler(['args' => $args]);

        self::assertSame('info', $result['structuredContent']['level']);
        // control characters (newlines included) are stripped before the newline replacement, as upstream
        self::assertSame('line1line2 red', $result['structuredContent']['message']);
        self::assertSame('logged', $result['structuredContent']['status']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $result['structuredContent']['timestamp']);
        self::assertContains('[MCP] line1line2 red', $this->logged('info'));
        self::assertSame($result['structuredContent'], json_decode($result['content'][0]['text'], true));

        $handler(['args' => ['message' => 'w', 'level' => 'warn']]);
        $handler(['args' => ['message' => 'e', 'level' => 'error']]);
        self::assertContains('[MCP] w', $this->logged('warning'));
        self::assertContains('[MCP] e', $this->logged('error'));
        self::assertSame(str_repeat('a', 10 * 1024) . '...[truncated]', Log::sanitize(str_repeat('a', 10 * 1024 + 5)));
    }
}
