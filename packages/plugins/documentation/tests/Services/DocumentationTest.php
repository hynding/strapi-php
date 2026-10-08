<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Tests\Services;

use PHPUnit\Framework\TestCase;
use Strapi\Plugin\Documentation\Config\DefaultPluginConfig;
use Strapi\Plugin\Documentation\Services\Documentation;
use Strapi\Plugin\Documentation\Services\Override;
use Strapi\Plugin\Documentation\Tests\Mocks\MockContentTypes;
use Strapi\Plugin\Documentation\Tests\Mocks\MockStrapiData;
use Strapi\Plugin\Documentation\Tests\Mocks\StrapiMock;
use Strapi\Types\Core\StrapiDirectories;

/**
 * Port of server/src/services/__tests__/documentation.test.ts.
 *
 * Upstream mocks `fs-extra`'s `writeJson`; here the document is written to a temporary
 * `src/extensions` and read back. `SwaggerParser.validate()` is replaced by a check that every
 * local `$ref` resolves (the Node-vs-PHP comparison validates real documents with swagger-parser).
 */
final class DocumentationTest extends TestCase
{
    private StrapiMock $strapi;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/strapi-documentation-test-' . bin2hex(random_bytes(4));
        $this->strapi = new StrapiMock(MockContentTypes::contentTypes(), MockStrapiData::components(), MockStrapiData::plugins(), MockStrapiData::apis(), $this->root);
        $this->strapi->configGet = static fn (string $path): mixed => $path === 'plugin::documentation' ? DefaultPluginConfig::defaultConfig() : null;
        $this->useOverrideService(new Override($this->strapi));
    }

    protected function tearDown(): void
    {
        self::rmrf($this->root);
    }

    private static function rmrf(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::rmrf("{$path}/{$entry}");
                }
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    private function useOverrideService(Override $override): void
    {
        $this->strapi->documentationServices['override'] = $override;
    }

    /** @param array<string, mixed> $config */
    private function useConfig(array $config): void
    {
        $this->strapi->configGet = static fn (string $path): mixed => $path === 'plugin::documentation' ? $config : null;
    }

    /** @return array<string, mixed> the last written full_documentation.json */
    private function generateFullDoc(?string $version = null): array
    {
        $docService = new Documentation($this->strapi);
        $docService->generateFullDoc($version);

        $file = "{$this->root}/src/extensions/documentation/documentation/" . ($version ?? '1.0.0') . '/full_documentation.json';
        self::assertFileExists($file);

        $doc = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($doc);

        return $doc;
    }

    /**
     * @param list<string> $refs
     */
    private static function collectRefs(mixed $value, array &$refs): void
    {
        if (!is_array($value)) {
            return;
        }
        if (is_string($value['$ref'] ?? null)) {
            $refs[] = $value['$ref'];
        }
        foreach ($value as $nested) {
            self::collectRefs($nested, $refs);
        }
    }

    public function testGeneratesAValidOpenapiSchema(): void
    {
        $mockFinalDoc = $this->generateFullDoc();

        self::assertSame('3.0.0', $mockFinalDoc['openapi']);
        $refs = [];
        self::collectRefs($mockFinalDoc, $refs);
        self::assertNotSame([], $refs);
        foreach ($refs as $ref) {
            self::assertStringStartsWith('#/components/schemas/', $ref);
            self::assertArrayHasKey(substr($ref, strlen('#/components/schemas/')), $mockFinalDoc['components']['schemas'], "unresolved \$ref: {$ref}");
        }
        foreach ($mockFinalDoc['paths'] as $pathItem) {
            foreach ($pathItem as $operation) {
                self::assertArrayHasKey('responses', $operation);
                self::assertArrayHasKey('operationId', $operation);
            }
        }
    }

    public function testGeneratesTheCorrectResponseComponentSchemaForASingleType(): void
    {
        $mockFinalDoc = $this->generateFullDoc();
        $expected = [
            'description' => 'OK',
            'content' => [
                'application/json' => [
                    'schema' => [
                        '$ref' => '#/components/schemas/HomepageResponse',
                    ],
                ],
            ],
        ];
        self::assertSame($expected, $mockFinalDoc['paths']['/homepage']['get']['responses'][200]);
        self::assertSame($expected, $mockFinalDoc['paths']['/homepage']['put']['responses'][200]);
        self::assertSame($expected, $mockFinalDoc['paths']['/homepage']['post']['responses'][200]);
    }

    public function testGeneratesTheCorrectResponseComponentSchemaForACollectionType(): void
    {
        $mockFinalDoc = $this->generateFullDoc();
        $expected = static fn (string $ref): array => [
            'description' => 'OK',
            'content' => [
                'application/json' => [
                    'schema' => [
                        '$ref' => "#/components/schemas/{$ref}",
                    ],
                ],
            ],
        ];
        self::assertSame($expected('KitchensinkListResponse'), $mockFinalDoc['paths']['/kitchensinks']['get']['responses'][200]);
        self::assertSame($expected('KitchensinkResponse'), $mockFinalDoc['paths']['/kitchensinks']['post']['responses'][200]);
        self::assertSame($expected('KitchensinkResponse'), $mockFinalDoc['paths']['/kitchensinks/{id}']['get']['responses'][200]);
        self::assertSame($expected('KitchensinkResponse'), $mockFinalDoc['paths']['/kitchensinks/{id}']['put']['responses'][200]);
    }

    // --- Determines the plugins that need documentation ------------------------------------

    public function testGeneratesDocumentationForTheDefaultPluginsIfTheUserProvidedNothingInTheConfig(): void
    {
        $mockFinalDoc = $this->generateFullDoc();

        self::assertSame(['upload', 'users-permissions'], $mockFinalDoc['x-strapi-config']['plugins']);
    }

    public function testGeneratesDocumentationOnlyForPluginsInTheUsersConfig(): void
    {
        $defaultConfig = DefaultPluginConfig::defaultConfig();
        $this->useConfig([...$defaultConfig, 'x-strapi-config' => [...$defaultConfig['x-strapi-config'], 'plugins' => ['upload']]]);

        $mockFinalDoc = $this->generateFullDoc();

        self::assertSame(['upload'], $mockFinalDoc['x-strapi-config']['plugins']);
    }

    public function testDoesNotGenerateDocumentationForAnyPlugins(): void
    {
        $defaultConfig = DefaultPluginConfig::defaultConfig();
        $this->useConfig([...$defaultConfig, 'x-strapi-config' => [...$defaultConfig['x-strapi-config'], 'plugins' => []]]);

        $mockFinalDoc = $this->generateFullDoc();

        self::assertSame([], $mockFinalDoc['x-strapi-config']['plugins']);
    }

    // --- Handles user config and overrides -------------------------------------------------

    public function testReplacesDefaultConfigWithTheUserConfig(): void
    {
        $userConfig = [
            'info' => [
                'version' => '4.0.0',
                'title' => 'custom-documentation',
                'description' => 'custom description',
                'termsOfService' => 'custom terms of service',
                'contact' => [
                    'name' => 'custom-team',
                    'email' => 'custom-contact-email@something.io',
                    'url' => 'custom-mywebsite.io',
                ],
                'license' => [
                    'name' => 'custom Apache 2.0',
                    'url' => 'custom https://www.apache.org/licenses/LICENSE-2.0.html',
                ],
            ],
            'x-strapi-config' => [
                'path' => 'custom-documentation',
                'plugins' => [],
            ],
            'servers' => [['server' => 'custom-server']],
            'externalDocs' => [
                'description' => 'custom Find out more',
                'url' => 'custom-doc-url',
            ],
            'webhooks' => [
                'test' => new \stdClass(),
            ],
            'security' => [
                [
                    'bearerAuth' => ['custom'],
                ],
            ],
        ];

        $this->useConfig($userConfig);
        $docService = new Documentation($this->strapi);
        $docService->generateFullDoc();
        $raw = (string) file_get_contents("{$this->root}/src/extensions/documentation/documentation/4.0.0/full_documentation.json");
        $mockFinalDoc = json_decode($raw, true);
        self::assertIsArray($mockFinalDoc);

        // The generation data is dynamically added, it cannot be modified by the user
        $mockFinalDocInfo = $mockFinalDoc['info'];
        unset($mockFinalDocInfo['x-generation-date']);
        self::assertSame($userConfig['info'], $mockFinalDocInfo);
        self::assertSame($userConfig['x-strapi-config'], $mockFinalDoc['x-strapi-config']);
        self::assertSame($userConfig['externalDocs'], $mockFinalDoc['externalDocs']);
        self::assertSame($userConfig['security'], $mockFinalDoc['security']);
        self::assertStringContainsString('"webhooks": {' . "\n" . '    "test": {}', $raw);
        self::assertSame($userConfig['servers'], $mockFinalDoc['servers']);
    }

    public function testDoesNotApplyAnOverrideIfThePluginProvidingTheOverrideIsntSpecifiedInTheXStrapiConfigPlugins(): void
    {
        $defaultConfig = DefaultPluginConfig::defaultConfig();
        $this->useConfig([...$defaultConfig, 'x-strapi-config' => [...$defaultConfig['x-strapi-config'], 'plugins' => []]]);
        $overrideService = new Override($this->strapi);

        $overrideService->registerOverride(
            [
                'paths' => [
                    '/test' => [
                        'get' => [
                            'tags' => ['Users-Permissions - Users & Roles'],
                            'summary' => 'Get list of users',
                            'responses' => [],
                        ],
                    ],
                ],
            ],
            ['pluginOrigin' => 'users-permissions'],
        );
        $this->useOverrideService($overrideService);

        $mockFinalDoc = $this->generateFullDoc();
        self::assertArrayNotHasKey('/test', $mockFinalDoc['paths']);
    }

    public function testOverridesExtendsTags(): void
    {
        $overrideService = new Override($this->strapi);
        // Simulate override from users-permissions plugin
        $overrideService->registerOverride(['tags' => ['users-permissions-tag']], ['pluginOrigin' => 'users-permissions']);
        // Simulate override from upload plugin
        $overrideService->registerOverride(['tags' => ['upload-tag']], ['pluginOrigin' => 'upload']);
        // Use the override service in the documentation service
        $this->useOverrideService($overrideService);

        $mockFinalDoc = $this->generateFullDoc();

        self::assertSame(['users-permissions-tag', 'upload-tag'], $mockFinalDoc['tags']);
    }

    public function testOverridesReplacesExistingOrAddsNewPaths(): void
    {
        $overrideService = new Override($this->strapi);
        // Simulate override from upload plugin
        $overrideService->registerOverride(
            [
                'paths' => [
                    // This path exists after generating with mock data, replace it
                    '/upload/files' => [
                        'get' => [
                            'responses' => ['existing-path-test'],
                        ],
                    ],
                    // This path does not exist after generating with mock data, add it
                    '/upload/new-path' => [
                        'get' => [
                            'responses' => ['new-path-test'],
                        ],
                    ],
                ],
            ],
            ['pluginOrigin' => 'upload'],
        );
        $this->useOverrideService($overrideService);

        $mockFinalDoc = $this->generateFullDoc();

        self::assertSame(['existing-path-test'], $mockFinalDoc['paths']['/upload/files']['get']['responses']);
        self::assertSame(['responses'], array_keys($mockFinalDoc['paths']['/upload/files']['get']));
        self::assertSame(['new-path-test'], $mockFinalDoc['paths']['/upload/new-path']['get']['responses']);
    }

    public function testOverridesReplacesExistingOrAddsNewComponents(): void
    {
        $overrideService = new Override($this->strapi);
        // Simulate override from upload plugin
        $overrideService->registerOverride(
            [
                'components' => [
                    'schemas' => [
                        // This component schema exists after generating with mock data, replace it
                        'UploadFileResponse' => [
                            'properties' => [
                                'data' => ['$ref' => 'test-existing-component'],
                                'meta' => ['type' => 'object'],
                            ],
                        ],
                        // This component schema does not exist after generating with mock data, add it
                        'UploadFileMockCompo' => [
                            'properties' => [
                                'data' => ['$ref' => 'test-new-component'],
                                'meta' => ['type' => 'object'],
                            ],
                        ],
                    ],
                ],
            ],
            ['pluginOrigin' => 'upload'],
        );
        $this->useOverrideService($overrideService);

        $mockFinalDoc = $this->generateFullDoc();

        self::assertSame('test-existing-component', $mockFinalDoc['components']['schemas']['UploadFileResponse']['properties']['data']['$ref']);
        self::assertSame('test-new-component', $mockFinalDoc['components']['schemas']['UploadFileMockCompo']['properties']['data']['$ref']);
    }

    public function testOverridesOnlyTheSpecifiedVersion(): void
    {
        $overrideService = new Override($this->strapi);
        // Simulate override from upload plugin: only override for version 1.0.0
        $overrideService->registerOverride(
            ['info' => ['version' => '1.0.0'], 'components' => ['schemas' => ['ShouldNotBeAdded' => new \stdClass()]]],
            ['pluginOrigin' => 'upload'],
        );
        // Only override for version 2.0.0
        $overrideService->registerOverride(
            ['info' => ['version' => '2.0.0'], 'components' => ['schemas' => ['ShouldBeAdded' => new \stdClass()]]],
            ['pluginOrigin' => 'upload'],
        );
        // No version: always applied
        $overrideService->registerOverride(
            ['components' => ['schemas' => ['ShouldAlsoBeAdded' => new \stdClass()]]],
            ['pluginOrigin' => 'upload'],
        );
        $this->useOverrideService($overrideService);

        $mockFinalDoc = $this->generateFullDoc('2.0.0');

        self::assertArrayNotHasKey('ShouldNotBeAdded', $mockFinalDoc['components']['schemas']);
        self::assertArrayHasKey('ShouldBeAdded', $mockFinalDoc['components']['schemas']);
        self::assertArrayHasKey('ShouldAlsoBeAdded', $mockFinalDoc['components']['schemas']);
    }

    public function testExcludesApisAndPluginsFromGeneration(): void
    {
        $overrideService = new Override($this->strapi);

        $overrideService->excludeFromGeneration('kitchensink');

        $this->useOverrideService($overrideService);

        $mockFinalDoc = $this->generateFullDoc();

        foreach (array_keys($mockFinalDoc['paths']) as $path) {
            self::assertStringNotContainsString('kitchensink', $path);
        }
        foreach (array_keys($mockFinalDoc['components']['schemas']) as $compo) {
            self::assertStringNotContainsString('Kitchensink', $compo);
        }
    }

    public function testAppliesAUsersMutateDocumentationFunction(): void
    {
        $defaultConfig = DefaultPluginConfig::defaultConfig();
        $this->useConfig([
            ...$defaultConfig,
            'x-strapi-config' => [
                ...$defaultConfig['x-strapi-config'],
                'mutateDocumentation' => static function (array &$draft): void {
                    $draft['paths']['/kitchensinks'] = [
                        'get' => ['responses' => [200 => ['description' => 'test']]],
                    ];
                },
            ],
        ]);

        $mockFinalDoc = $this->generateFullDoc();

        self::assertSame(['get' => ['responses' => [200 => ['description' => 'test']]]], $mockFinalDoc['paths']['/kitchensinks']);
        self::assertArrayNotHasKey('mutateDocumentation', $mockFinalDoc['x-strapi-config']);
    }

    // --- Filesystem paths for generated OpenAPI (app vs dist) ------------------------------

    /**
     * Regression upstream: OpenAPI files must resolve to dist.* in production when distDir !== appDir
     * (TypeScript builds, https://github.com/strapi/strapi/issues/22701). The PHP port has no build
     * step (dist = app): every environment uses the app's directories.
     */
    public function testUsesTheAppExtensionsForGetFullDocumentationPathInEveryEnvironment(): void
    {
        $this->strapi->dirs = StrapiDirectories::fromRoot('/mock-project');

        foreach (['production', 'development'] as $environment) {
            $this->strapi->environment = $environment;
            $docService = new Documentation($this->strapi);
            self::assertSame('/mock-project/src/extensions/documentation/documentation', $docService->getFullDocumentationPath());
        }
    }

    public function testUsesTheAppPathsForPluginAndApiDocsInEveryEnvironment(): void
    {
        $this->strapi->dirs = StrapiDirectories::fromRoot('/mock-project');

        foreach (['production', 'test'] as $environment) {
            $this->strapi->environment = $environment;
            $docService = new Documentation($this->strapi);
            self::assertSame('/mock-project/src/extensions/upload/documentation', $docService->getApiDocumentationPath(['name' => 'upload', 'getter' => 'plugin']));
            self::assertSame('/mock-project/src/api/kitchensink/documentation', $docService->getApiDocumentationPath(['name' => 'kitchensink', 'getter' => 'api']));
        }
    }

    // --- PHP-port additions ----------------------------------------------------------------

    public function testListsTheGeneratedVersionsAndDeletesOne(): void
    {
        $this->generateFullDoc();
        $this->generateFullDoc('2.0.0');
        @mkdir("{$this->root}/src/extensions/documentation/documentation/not-a-version", 0777, true);

        $docService = new Documentation($this->strapi);
        $versions = $docService->getDocumentationVersions();

        self::assertSame(['1.0.0', '2.0.0'], array_column($versions, 'version'));
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', (string) $versions[0]['generatedDate']);
        self::assertSame('', $versions[0]['url']);

        $docService->deleteDocumentation('2.0.0');
        self::assertSame(['1.0.0'], array_column($docService->getDocumentationVersions(), 'version'));
    }

    public function testWritesTheDocumentLikeJsonStringifyWithTwoSpaces(): void
    {
        $this->generateFullDoc();
        $raw = (string) file_get_contents("{$this->root}/src/extensions/documentation/documentation/1.0.0/full_documentation.json");

        self::assertStringStartsWith("{\n  \"openapi\": \"3.0.0\",\n  \"info\": {\n    \"version\": \"1.0.0\",", $raw);
        self::assertStringEndsWith("}\n", $raw);
        self::assertStringContainsString('"json": {}', $raw);
        self::assertStringContainsString('"url": "https://www.apache.org/licenses/LICENSE-2.0.html"', $raw);
    }
}
