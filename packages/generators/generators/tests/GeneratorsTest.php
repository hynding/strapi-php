<?php

declare(strict_types=1);

namespace Strapi\Generators\Tests;

use Strapi\Generators\Generators;

require_once __DIR__ . '/GeneratorsTestCase.php';

/**
 * Not an upstream test file (upstream only snapshots the content-type generator): snapshots of
 * what every generator writes, in its PHP form.
 */
final class GeneratorsTest extends GeneratorsTestCase
{
    /** @param array<string, mixed> $answers */
    private function generate(string $generator, array $answers): void
    {
        Generators::generate($generator, $answers, ['dir' => $this->outputDirectory]);
    }

    public function testUnknownGenerator(): void
    {
        $this->expectExceptionMessage('Generator "nope" not found');

        $this->generate('nope', []);
    }

    public function testApi(): void
    {
        $this->generate('api', ['id' => 'hello', 'isPluginApi' => false]);

        self::assertSame([
            'src/api/hello/controllers/hello.php',
            'src/api/hello/routes/hello.php',
            'src/api/hello/services/hello.php',
        ], self::files($this->outputDirectory));

        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            /**
             * A set of functions called "actions" for `hello`
             */

            return [
                // 'exampleAction' => static function (\Strapi\Types\Core\Context $ctx): void {
                //     try {
                //         $ctx->setBody('ok');
                //     } catch (\Throwable $err) {
                //         $ctx->setBody($err->getMessage());
                //     }
                // },
            ];

            PHP, self::read("{$this->outputDirectory}/src/api/hello/controllers/hello.php"));

        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            use Strapi\Core\Strapi;

            /**
             * hello service
             */

            return static fn (Strapi $strapi): array => [];

            PHP, self::read("{$this->outputDirectory}/src/api/hello/services/hello.php"));

        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'routes' => [
                    // [
                    //     'method' => 'GET',
                    //     'path' => '/hello',
                    //     'handler' => 'hello.exampleAction',
                    //     'config' => [
                    //         'policies' => [],
                    //         'middlewares' => [],
                    //     ],
                    // ],
                ],
            ];

            PHP, self::read("{$this->outputDirectory}/src/api/hello/routes/hello.php"));

        foreach (self::files($this->outputDirectory) as $file) {
            self::assertValidPhp("{$this->outputDirectory}/{$file}");
        }
    }

    public function testControllerAndServiceInAnExistingApi(): void
    {
        $this->generate('controller', ['id' => 'extra', 'destination' => 'api', 'api' => 'article']);
        $this->generate('service', ['id' => 'extra', 'destination' => 'api', 'api' => 'article']);

        self::assertSame([
            'src/api/article/controllers/extra.php',
            'src/api/article/services/extra.php',
        ], self::files($this->outputDirectory));
    }

    public function testPolicy(): void
    {
        $this->generate('policy', ['id' => 'is-owner', 'destination' => 'root']);

        self::assertSame(['src/policies/is-owner.php'], self::files($this->outputDirectory));
        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            use Strapi\Core\Strapi;
            use Strapi\Utils\Policy\PolicyContext;

            /**
             * `is-owner` policy
             */
            return static function (PolicyContext $policyCtx, array $config, Strapi $strapi): bool {
                // Add your own logic here.
                $strapi->log()->info('In is-owner policy.');

                $canDoSomething = true;

                if ($canDoSomething) {
                    return true;
                }

                return false;
            };

            PHP, self::read("{$this->outputDirectory}/src/policies/is-owner.php"));
        self::assertValidPhp("{$this->outputDirectory}/src/policies/is-owner.php");
    }

    public function testMiddleware(): void
    {
        $this->generate('middleware', ['name' => 'timer', 'destination' => 'api', 'api' => 'article']);

        self::assertSame(['src/api/article/middlewares/timer.php'], self::files($this->outputDirectory));
        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            use Strapi\Core\Strapi;
            use Strapi\Types\Core\Context;

            /**
             * `timer` middleware
             */
            return static function (array $config, Strapi $strapi): callable {
                // Add your own logic here.
                return static function (Context $ctx, callable $next) use ($strapi): void {
                    $strapi->log()->info('In timer middleware.');

                    $next();
                };
            };

            PHP, self::read("{$this->outputDirectory}/src/api/article/middlewares/timer.php"));
        self::assertValidPhp("{$this->outputDirectory}/src/api/article/middlewares/timer.php");
    }

    public function testMigration(): void
    {
        $this->generate('migration', ['name' => 'add-index']);

        $files = self::files($this->outputDirectory);
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('~^database/migrations/\d{4}\.\d\d\.\d\dT\d\d\.\d\d\.\d\d\.add-index\.php$~', $files[0]);
        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            use Doctrine\DBAL\Connection;
            use Strapi\Database\Database;

            /**
             * Migration `add-index`
             */
            return [
                /**
                 * Runs in a transaction: `$trx` is the DBAL connection, `$db` the Strapi database.
                 */
                'up' => static function (Connection $trx, Database $db): void {
                },
            ];

            PHP, self::read("{$this->outputDirectory}/{$files[0]}"));

        $migration = require "{$this->outputDirectory}/{$files[0]}";
        self::assertIsCallable($migration['up']);
    }

    public function testPlugin(): void
    {
        $this->generate('plugin', [
            'pluginId' => 'my-plugin',
            'composerName' => 'acme/my-plugin',
            'displayName' => 'My Plugin',
            'description' => 'Does things',
            'authorName' => 'Ada',
            'authorEmail' => 'ada@example.com',
            'license' => 'MIT',
            'client-code' => true,
            'pkgName' => '@acme/my-plugin',
            'typescript' => true,
        ]);

        $root = "{$this->outputDirectory}/src/plugins/my-plugin";
        self::assertSame([
            '.gitignore',
            'README.md',
            'admin/custom.d.ts',
            'admin/src/components/Initializer.tsx',
            'admin/src/components/PluginIcon.tsx',
            'admin/src/index.ts',
            'admin/src/pages/App.tsx',
            'admin/src/pages/HomePage.tsx',
            'admin/src/pluginId.ts',
            'admin/src/translations/en.json',
            'admin/src/utils/getTranslation.ts',
            'admin/tsconfig.build.json',
            'admin/tsconfig.json',
            'composer.json',
            'package.json',
            'server/src/bootstrap.php',
            'server/src/config/index.php',
            'server/src/content-types/index.php',
            'server/src/controllers/controller.php',
            'server/src/controllers/index.php',
            'server/src/destroy.php',
            'server/src/index.php',
            'server/src/middlewares/index.php',
            'server/src/policies/index.php',
            'server/src/register.php',
            'server/src/routes/admin/index.php',
            'server/src/routes/content-api/index.php',
            'server/src/routes/index.php',
            'server/src/services/index.php',
            'server/src/services/service.php',
            'strapi-server.php',
        ], self::files($root));

        self::assertSame([
            'name' => 'acme/my-plugin',
            'description' => 'Does things',
            'type' => 'library',
            'license' => 'MIT',
            'authors' => [['name' => 'Ada', 'email' => 'ada@example.com']],
            'require' => ['php' => '>=8.3', 'strapi/core' => '^5.0'],
            'autoload' => ['classmap' => ['server/src/']],
            'extra' => ['strapi' => [
                'kind' => 'plugin',
                'name' => 'my-plugin',
                'displayName' => 'My Plugin',
                'description' => 'Does things',
                'server' => 'strapi-server.php',
                'namespace' => 'StrapiPlugin\\MyPlugin',
            ]],
        ], self::readJSON("{$root}/composer.json"));

        $package = self::readJSON("{$root}/package.json");
        self::assertSame('@acme/my-plugin', $package['name']);
        self::assertSame('Ada <ada@example.com>', $package['author']);
        self::assertSame(['kind' => 'plugin', 'name' => 'my-plugin', 'displayName' => 'My Plugin', 'description' => 'Does things'], $package['strapi']);
        self::assertSame(['./package.json', './strapi-admin'], array_keys($package['exports']));
        self::assertSame('./admin/src/index.ts', $package['exports']['./strapi-admin']['source']);
        self::assertStringContainsString('"dependencies": {}', self::read("{$root}/package.json"));
        self::assertSame("export const PLUGIN_ID = 'my-plugin';\n", self::read("{$root}/admin/src/pluginId.ts"));

        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            use Strapi\Core\Strapi;
            use Strapi\Types\Core\Context;

            return static fn (Strapi $strapi): array => [
                'index' => static function (Context $ctx) use ($strapi): void {
                    $ctx->setBody(
                        $strapi
                            ->plugin('my-plugin')
                            // the name of the service file & the method.
                            ->service('service')
                            ->getWelcomeMessage(),
                    );
                },
            ];

            PHP, self::read("{$root}/server/src/controllers/controller.php"));

        foreach (self::files($root) as $file) {
            if (str_ends_with($file, '.php')) {
                self::assertValidPhp("{$root}/{$file}");
            }
        }

        // the module the plugin loader reads
        $module = require "{$root}/strapi-server.php";
        self::assertSame(['register', 'bootstrap', 'destroy', 'config', 'controllers', 'routes', 'services', 'contentTypes', 'policies', 'middlewares'], array_keys($module));
        self::assertSame(['default' => []], array_intersect_key($module['config'], ['default' => true]));
        self::assertSame(['content-api', 'admin'], array_keys($module['routes']));
    }

    public function testPluginWithoutAdmin(): void
    {
        $this->generate('plugin', ['pluginId' => 'server-only', 'license' => 'MIT', 'client-code' => false]);

        $files = self::files("{$this->outputDirectory}/src/plugins/server-only");
        self::assertNotContains('package.json', $files);
        self::assertSame([], array_filter($files, static fn (string $f): bool => str_starts_with($f, 'admin/')));
        self::assertSame('strapi-plugin/server-only', self::readJSON("{$this->outputDirectory}/src/plugins/server-only/composer.json")['name']);
    }

    public function testPluginWithAJavascriptAdmin(): void
    {
        $this->generate('plugin', ['pluginId' => 'js-admin', 'license' => 'MIT', 'client-code' => true, 'typescript' => false]);

        $root = "{$this->outputDirectory}/src/plugins/js-admin";
        $files = self::files($root);
        self::assertContains('admin/src/index.js', $files);
        self::assertContains('admin/jsconfig.json', $files);
        self::assertNotContains('admin/tsconfig.json', $files);
        self::assertSame('./admin/src/index.js', self::readJSON("{$root}/package.json")['exports']['./strapi-admin']['source']);
        self::assertArrayNotHasKey('types', self::readJSON("{$root}/package.json")['exports']['./strapi-admin']);
    }

    public function testApiContentTypeAndFilesInAPlugin(): void
    {
        $this->generate('plugin', ['pluginId' => 'shop', 'license' => 'MIT', 'client-code' => false]);
        $this->generate('api', ['id' => 'cart', 'isPluginApi' => true, 'plugin' => 'shop']);
        $this->generate('content-type', [
            'displayName' => 'Product',
            'singularName' => 'product',
            'pluralName' => 'products',
            'kind' => 'collectionType',
            'destination' => 'plugin',
            'plugin' => 'shop',
            'bootstrapApi' => true,
            'attributes' => [['attributeName' => 'name', 'attributeType' => 'string']],
        ]);
        $this->generate('policy', ['id' => 'is-admin', 'destination' => 'plugin', 'plugin' => 'shop']);
        $this->generate('middleware', ['name' => 'audit', 'destination' => 'plugin', 'plugin' => 'shop']);

        $server = "{$this->outputDirectory}/src/plugins/shop/server/src";

        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'controller' => require __DIR__ . '/controller.php',
                'cart' => require __DIR__ . '/cart.php',
                'product' => require __DIR__ . '/product.php',
            ];

            PHP, self::read("{$server}/controllers/index.php"));

        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            use Strapi\Core\Strapi;

            return static fn (Strapi $strapi): array => [
                'type' => 'content-api',
                'routes' => [
                    [
                        'method' => 'GET',
                        'path' => '/',
                        // name of the controller file & the method.
                        'handler' => 'controller.index',
                        'config' => [
                            'policies' => [],
                        ],
                    ],
                    ...(require __DIR__ . '/cart.php')['routes'],
                    ...(require __DIR__ . '/product.php')->routes($strapi),
                ],
            ];

            PHP, self::read("{$server}/routes/content-api/index.php"));

        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'product' => [
                    'schema' => json_decode((string) file_get_contents(__DIR__ . '/product/schema.json'), true, flags: JSON_THROW_ON_ERROR),
                ],
            ];

            PHP, self::read("{$server}/content-types/index.php"));

        self::assertStringContainsString("Factories::createCoreController('plugin::shop.product')", self::read("{$server}/controllers/product.php"));
        self::assertStringContainsString("'is-admin' => require __DIR__ . '/is-admin.php',", self::read("{$server}/policies/index.php"));
        self::assertStringContainsString("'audit' => require __DIR__ . '/audit.php',", self::read("{$server}/middlewares/index.php"));

        $module = require "{$this->outputDirectory}/src/plugins/shop/strapi-server.php";
        self::assertSame(['product'], array_keys($module['contentTypes']));
        self::assertSame('product', $module['contentTypes']['product']['schema']['info']['singularName']);
        self::assertSame(['controller', 'cart', 'product'], array_keys($module['controllers']));
        self::assertSame(['service', 'cart', 'product'], array_keys($module['services']));
        self::assertSame(['is-admin'], array_keys($module['policies']));
        self::assertSame(['audit'], array_keys($module['middlewares']));
    }

    public function testAnApiForAPluginWithoutIndexFilesCreatesThem(): void
    {
        mkdir("{$this->outputDirectory}/src/plugins/bare", 0o777, true);

        $this->generate('api', ['id' => 'thing', 'isPluginApi' => true, 'plugin' => 'bare']);

        $server = "{$this->outputDirectory}/src/plugins/bare/server/src";
        self::assertSame([
            'controllers/index.php',
            'controllers/thing.php',
            'routes/admin/index.php',
            'routes/content-api/index.php',
            'routes/content-api/thing.php',
            'routes/index.php',
            'services/index.php',
            'services/thing.php',
        ], self::files($server));
        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            use Strapi\Core\Strapi;

            return static fn (Strapi $strapi): array => [
                'type' => 'admin',
                'routes' => [],
            ];

            PHP, self::read("{$server}/routes/admin/index.php"));

        $routes = require "{$server}/routes/index.php";
        self::assertSame(['content-api', 'admin'], array_keys($routes));
        foreach (self::files($server) as $file) {
            self::assertValidPhp("{$server}/{$file}");
        }
    }
}
