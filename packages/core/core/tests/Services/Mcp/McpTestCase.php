<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Services\Mcp;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Strapi\Core\Services\Mcp\Internal\McpCapabilityDefinitionRegistry;
use Strapi\Core\Services\Mcp\Internal\McpServerFactory;
use Strapi\Core\Services\Mcp\Sdk\McpServer;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Permissions\Engine\Abilities\Ability;
use Strapi\Permissions\Engine\Abilities\AbilityBuilder;

/**
 * Upstream's MCP unit tests mock `strapi` (`log`, `telemetry`, `config`) and the SDK. `Strapi` is
 * final: these tests use an un-loaded `examples/getstarted` instance with a recording logger
 * (telemetry is a no-op that logs `Telemetry is disabled: event … was not sent` with the payload),
 * and talk to the McpServer through JSON-RPC messages, as upstream's in-memory client does.
 */
abstract class McpTestCase extends TestCase
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    protected array $logs = [];

    protected function createStrapi(): Strapi
    {
        putenv('DATABASE_CLIENT=sqlite');
        putenv('DATABASE_FILENAME=:memory:');
        putenv('STRAPI_NO_EXIT=1');
        $_ENV['DATABASE_CLIENT'] = 'sqlite';
        $_ENV['DATABASE_FILENAME'] = ':memory:';
        $strapi = new Strapi(['appDir' => dirname(__DIR__, 6) . '/examples/getstarted']);
        $logs = &$this->logs;
        $strapi->set('logger', new class ($logs) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string, context: array<mixed>}> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        });

        return $strapi;
    }

    /** @return list<string> */
    protected function logged(string $level): array
    {
        return array_values(array_map(static fn (array $l): string => $l['message'], array_filter($this->logs, static fn (array $l): bool => $l['level'] === $level)));
    }

    /** @return list<array{event: string, payload: mixed}> */
    protected function telemetry(): array
    {
        $out = [];
        foreach ($this->logs as $log) {
            if (preg_match('/^Telemetry is disabled: event (\S+) was not sent$/', $log['message'], $m) === 1) {
                $out[] = ['event' => $m[1], 'payload' => $log['context']['payload'] ?? []];
            }
        }

        return $out;
    }

    /** @param list<string> $actions */
    protected static function ability(array $actions = ['test.read']): Ability
    {
        $builder = new AbilityBuilder();
        foreach ($actions as $action) {
            $builder->can($action);
        }

        return $builder->build();
    }

    /** @return array{tools: McpCapabilityDefinitionRegistry, prompts: McpCapabilityDefinitionRegistry, resources: McpCapabilityDefinitionRegistry} */
    protected static function definitions(): array
    {
        return [
            'tools' => new McpCapabilityDefinitionRegistry('tool'),
            'prompts' => new McpCapabilityDefinitionRegistry('prompt'),
            'resources' => new McpCapabilityDefinitionRegistry('resource'),
        ];
    }

    /**
     * @param array{tools: McpCapabilityDefinitionRegistry, prompts: McpCapabilityDefinitionRegistry, resources: McpCapabilityDefinitionRegistry} $definitions
     * @param list<string> $actions
     */
    protected function server(Strapi $strapi, array $definitions, bool $isDevMode = false, array $actions = ['test.read']): McpServer
    {
        return McpServerFactory::createMcpServerWithRegistries([
            'strapi' => $strapi,
            'definitions' => $definitions,
            'isDevMode' => $isDevMode,
            'ability' => self::ability($actions),
            'user' => ['id' => 1],
        ])['mcpServer'];
    }

    /**
     * A JSON-RPC request, answered.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected static function rpc(McpServer $server, string $method, array $params = [], int $id = 1): array
    {
        $response = $server->handleMessage(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], ['headers' => ['x-test' => 'yes'], 'url' => 'http://localhost/mcp']);
        self::assertNotNull($response);

        return json_decode((string) json_encode($response), true);
    }

    /** @param array<string, string|list<string>> $headers */
    protected static function context(string $method = 'POST', string $path = '/mcp', array $headers = [], mixed $body = null): Context
    {
        $request = new ServerRequest($method, 'http://localhost' . $path, $headers);
        if (is_array($body)) {
            $request = $request->withParsedBody($body);
        }

        return new Context($request);
    }

    /** @return array<string, mixed>|null */
    protected static function sseResponse(Context $ctx): ?array
    {
        $body = $ctx->body();
        if (!is_string($body) || preg_match_all('/^data: (.*)$/m', $body, $m) === 0) {
            return null;
        }

        return json_decode(end($m[1]), true);
    }
}
