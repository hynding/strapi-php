<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Services\Mcp;

use Strapi\Core\Services\Mcp\Internal\McpCapabilityDefinitionRegistry;
use Strapi\Core\Services\Mcp\Internal\McpCapabilityRegistry;
use Strapi\Core\Services\Mcp\Internal\McpConfiguration;
use Strapi\Core\Services\Mcp\Internal\SyncMcpSessionCapabilities;
use Strapi\Core\Services\Mcp\Sdk\McpServer;
use Strapi\Core\Services\Mcp\Sdk\RegisteredCapability;

/**
 * Ports of internal/__tests__/{McpCapabilityDefinitionRegistry,McpCapabilityRegistry,
 * McpConfiguration,syncMcpSessionCapabilities}.test.ts.
 */
final class RegistriesTest extends McpTestCase
{
    public function testDefinitionRegistry(): void
    {
        $registry = new McpCapabilityDefinitionRegistry('tool');
        self::assertSame('tool', $registry->capability);

        $definition = ['name' => 'my-capability', 'auth' => ['policies' => [['action' => 'test.action']]]];
        $registry->define($definition);
        self::assertSame($definition, $registry->get('my-capability'));
        self::assertNull($registry->get('unknown'));
        self::assertSame(1, $registry->size());
        self::assertSame([$definition], $registry->getAll());

        $prompts = new McpCapabilityDefinitionRegistry('prompt');
        $prompts->define(['name' => 'duplicate', 'devModeOnly' => true]);
        try {
            $prompts->define(['name' => 'duplicate', 'auth' => ['policies' => [['action' => 'test.action']]]]);
            self::fail('duplicate');
        } catch (\RuntimeException $e) {
            self::assertSame('[MCP] prompt with name "duplicate" is already registered. Names must be unique.', $e->getMessage());
        }
        self::assertSame(['name' => 'duplicate', 'devModeOnly' => true], $prompts->get('duplicate'));

        foreach ([['name' => 'missing-auth', 'devModeOnly' => false], ['name' => 'missing-auth', 'auth' => ['policies' => [['action' => '']]]], ['name' => 'missing-auth', 'auth' => ['policies' => []]]] as $bad) {
            try {
                (new McpCapabilityDefinitionRegistry('tool'))->define($bad);
                self::fail('missing auth');
            } catch (\RuntimeException $e) {
                self::assertSame('[MCP] tool with name "missing-auth" must declare auth policies or be devModeOnly.', $e->getMessage());
            }
        }

        $resources = new McpCapabilityDefinitionRegistry('resource');
        $a = ['name' => 'a', 'auth' => ['policies' => [['action' => 'test.action']]]];
        $b = ['name' => 'b', 'devModeOnly' => true];
        $resources->define($a);
        $resources->define($b);
        self::assertSame([$a, $b], $resources->getAll());
        self::assertTrue($resources->delete('a'));
        self::assertSame([$b], $resources->getAll());
        self::assertFalse($resources->delete('a'));
        self::assertFalse($resources->delete('unknown'));
        self::assertSame(1, $resources->size());
    }

    private static function capabilityRegistry(McpCapabilityDefinitionRegistry $definitions): McpCapabilityRegistry
    {
        return new class ($definitions) extends McpCapabilityRegistry {
            public function bind(McpServer $mcpServer): void
            {
                $this->registerDefinitions();
            }

            public function registerDefinitions(): void
            {
                $this->register(static fn (): RegisteredCapability => new RegisteredCapability());
            }
        };
    }

    public function testCapabilityRegistryBase(): void
    {
        $definitions = new McpCapabilityDefinitionRegistry('tool');
        $auth = ['policies' => [['action' => 'test.action']]];
        $definitions->define(['name' => 'tool-1', 'title' => 'Tool 1', 'auth' => $auth]);
        $definitions->define(['name' => 'tool-2', 'title' => 'Tool 2', 'devModeOnly' => true]);
        $definitions->define(['name' => 'tool-3', 'title' => 'Tool 3', 'auth' => $auth]);
        $registry = self::capabilityRegistry($definitions);

        // defined but not registered
        self::assertSame('defined', $registry->status('tool-1'));
        self::assertSame('undefined', $registry->status('unknown-tool'));
        self::assertSame(
            [
                ['name' => 'tool-1', 'status' => 'defined', 'devModeOnly' => false, 'auth' => $auth],
                ['name' => 'tool-2', 'status' => 'defined', 'devModeOnly' => true, 'auth' => null],
                ['name' => 'tool-3', 'status' => 'defined', 'devModeOnly' => false, 'auth' => $auth],
            ],
            $registry->list(['filter' => ['status' => ['defined']]]),
        );

        $registry->bind(new McpServer(['name' => 't', 'version' => '1']));
        // disabled by default after registration
        self::assertSame(['disabled', 'disabled', 'disabled'], array_column($registry->list(), 'status'));

        try {
            $registry->bind(new McpServer(['name' => 't', 'version' => '1']));
            self::fail('double bind');
        } catch (\RuntimeException $e) {
            self::assertSame('[MCP] tool with name "tool-1" is already registered. Names must be unique.', $e->getMessage());
        }

        $registry->enable('tool-1');
        self::assertSame('enabled', $registry->status('tool-1'));
        self::assertSame(['tool-1'], array_column($registry->list(['filter' => ['status' => ['enabled']]]), 'name'));
        self::assertSame(['tool-2', 'tool-3'], array_column($registry->list(['filter' => ['status' => ['disabled']]]), 'name'));
        self::assertCount(3, $registry->list(['filter' => ['status' => ['enabled', 'disabled']]]));

        $registry->enableAll();
        self::assertSame(['enabled', 'enabled', 'enabled'], array_column($registry->list(), 'status'));
        $registry->disable('tool-2');
        self::assertSame('disabled', $registry->status('tool-2'));
        $registry->disableAll();
        self::assertSame([], $registry->list(['filter' => ['status' => ['enabled']]]));

        $registry->remove('tool-1');
        self::assertSame('defined', $registry->status('tool-1'));
        $registry->removeAll();
        self::assertSame('defined', $registry->status('tool-3'));

        foreach (['enable', 'disable', 'remove'] as $method) {
            try {
                $registry->{$method}('unknown-tool');
                self::fail($method);
            } catch (\RuntimeException $e) {
                self::assertSame('[MCP] tool with name "unknown-tool" is not registered.', $e->getMessage());
            }
        }

        $empty = self::capabilityRegistry(new McpCapabilityDefinitionRegistry('tool'));
        $empty->enableAll();
        $empty->disableAll();
        $empty->removeAll();
        self::assertSame([], $empty->list());
    }

    public function testConfiguration(): void
    {
        $strapi = $this->createStrapi();
        $strapi->config()->set('server.mcp', []); // examples/getstarted enables it
        $config = new McpConfiguration($strapi);
        self::assertSame('/mcp', $config->path);
        self::assertSame(5 * 1000, $config->connectTimeoutMs);
        self::assertSame(60 * 1000, $config->requestTimeoutMs);
        self::assertFalse($config->isEnabled());

        $strapi->config()->set('server.mcp.connectTimeoutMs', 10 * 1000);
        $strapi->config()->set('server.mcp.requestTimeoutMs', 120 * 1000);
        $strapi->config()->set('server.mcp.enabled', true);
        $strapi->config()->set('autoReload', true);
        $config = new McpConfiguration($strapi);
        self::assertSame(10 * 1000, $config->connectTimeoutMs);
        self::assertSame(120 * 1000, $config->requestTimeoutMs);
        self::assertTrue($config->isEnabled());
        self::assertTrue($config->isDevMode());

        $strapi->config()->set('server.mcp.enabled', false);
        $strapi->config()->set('autoReload', false);
        self::assertFalse($config->isEnabled());
        self::assertFalse($config->isDevMode());
    }

    public function testSyncMcpSessionCapabilities(): void
    {
        $definitions = self::definitions();
        $definitions['tools']->define(['name' => 'read-tool', 'auth' => ['policies' => [['action' => 'test.read']]]]);
        $definitions['tools']->define(['name' => 'subject-tool', 'auth' => ['policies' => [['action' => 'test.read', 'subject' => 'api::a.a']]]]);
        $definitions['tools']->define(['name' => 'multi-tool', 'auth' => ['policies' => [['action' => 'test.write'], ['action' => 'test.other']]]]);
        $definitions['prompts']->define(['name' => 'dev-prompt', 'devModeOnly' => true]);
        $registries = [];
        foreach ($definitions as $kind => $defs) {
            $registries[$kind] = self::capabilityRegistry($defs);
            $registries[$kind]->bind(new McpServer(['name' => 't', 'version' => '1']));
        }

        // enables a disabled capability when the ability allows it (any policy of several)
        $summary = SyncMcpSessionCapabilities::syncMcpSessionCapabilities($registries, $definitions, self::ability(['test.read', 'test.other']), false);
        self::assertSame(['enabled' => ['read-tool', 'subject-tool', 'multi-tool'], 'disabled' => []], $summary);
        self::assertSame('disabled', $registries['prompts']->status('dev-prompt'), 'devModeOnly follows dev mode only');

        // does not mutate registries when the current status already matches
        self::assertSame(['enabled' => [], 'disabled' => []], SyncMcpSessionCapabilities::syncMcpSessionCapabilities($registries, $definitions, self::ability(['test.read', 'test.other']), false));

        // disables an enabled capability when a fresh ability denies it
        $summary = SyncMcpSessionCapabilities::syncMcpSessionCapabilities($registries, $definitions, self::ability(['test.other']), true);
        self::assertSame(['enabled' => ['dev-prompt'], 'disabled' => ['read-tool', 'subject-tool']], $summary);

        // the subject is passed through; entity conditions are not evaluated at session level
        $conditional = (new \Strapi\Permissions\Engine\Abilities\AbilityBuilder())->can('test.read', 'api::a.a', ['title'], ['id' => 5])->build();
        self::assertTrue(SyncMcpSessionCapabilities::canUseMcpCapability($conditional, ['name' => 'x', 'auth' => ['policies' => [['action' => 'test.read', 'subject' => 'api::a.a']]]], false));
        self::assertFalse(SyncMcpSessionCapabilities::canUseMcpCapability($conditional, ['name' => 'x', 'auth' => ['policies' => [['action' => 'test.read', 'subject' => 'api::b.b']]]], false));
        self::assertFalse(SyncMcpSessionCapabilities::canUseMcpCapability(self::ability(), ['name' => 'x'], true));
    }
}
