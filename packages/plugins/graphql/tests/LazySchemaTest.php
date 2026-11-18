<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Tests;

require_once __DIR__ . '/BootedApp.php';

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use Strapi\Core\Core;
use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Bootstrap;

/**
 * PHP port: without artifact generation (production, by default) the schema and the Apollo server
 * are built on the first request to the GraphQL endpoint, not during bootstrap. Under PHP-FPM
 * every request boots, and most are not GraphQL requests.
 */
final class LazySchemaTest extends TestCase
{
    private static ?Strapi $strapi = null;

    public static function setUpBeforeClass(): void
    {
        BootedApp::boot()->destroy(); // sets the environment BootedApp uses

        // what production gets by default; this app runs in development, where artifacts are on
        self::$strapi = Core::createStrapi(['appDir' => dirname(__DIR__, 4) . '/examples/getstarted'])->register();
        self::$strapi->config()->set('plugin::graphql.generateArtifacts', false);
        self::$strapi->bootstrap();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    private static function strapi(): Strapi
    {
        return self::$strapi ?? throw new \LogicException('Strapi is not booted');
    }

    private static function serverBuilt(): bool
    {
        $servers = (new \ReflectionProperty(Bootstrap::class, 'servers'))->getValue();

        return $servers instanceof \WeakMap && isset($servers[self::strapi()]);
    }

    public function testBuildsEagerlyOnlyWhenArtifactsAreGenerated(): void
    {
        self::assertFalse(Bootstrap::buildsEagerly(self::strapi()));
        self::assertTrue(Bootstrap::buildsEagerly(BootedApp::shared()));
    }

    public function testBootstrapLeavesTheServerUnbuiltUntilTheFirstGraphqlRequest(): void
    {
        self::assertFalse(self::serverBuilt());

        // the route is registered all the same
        $response = BootedApp::graphql(self::strapi(), ['query' => '{ __typename }']);

        self::assertSame(['data' => ['__typename' => 'Query']], $response['body']);
        self::assertTrue(self::serverBuilt());
    }

    #[Depends('testBootstrapLeavesTheServerUnbuiltUntilTheFirstGraphqlRequest')]
    public function testLaterRequestsReuseTheServer(): void
    {
        $servers = (new \ReflectionProperty(Bootstrap::class, 'servers'))->getValue();
        self::assertInstanceOf(\WeakMap::class, $servers);
        $server = $servers[self::strapi()];

        $response = BootedApp::graphql(self::strapi(), ['query' => '{ articles { documentId } }']);

        self::assertSame(200, $response['status']);
        self::assertSame($server, $servers[self::strapi()]);
    }
}
