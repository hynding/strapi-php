<?php

declare(strict_types=1);

namespace Strapi\Openapi\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Openapi\Assemblers\Document\Path\PathItem\Operation\OperationResponsesAssembler;
use Strapi\Openapi\Context\Factories\DocumentContextFactory;
use Strapi\Openapi\Context\Factories\OperationContextFactory;
use Strapi\Openapi\PostProcessor\ComponentsWriter;
use Strapi\Openapi\Tests\Helpers\ContentApiSchemaRegistry;
use Strapi\Openapi\Tests\Mocks\StrapiConfigMock;
use Strapi\Openapi\Utils\Zod;
use Strapi\Utils\Zod as z;

/** Port of __tests__/zod-to-openapi.test.ts. */
final class ZodToOpenapiTest extends TestCase
{
    /**
     * @param list<string> $refs
     */
    private static function collectLocalRefs(mixed $value, array &$refs): void
    {
        if (!is_array($value)) {
            return;
        }

        $ref = $value['$ref'] ?? null;
        if (is_string($ref) && str_starts_with($ref, '#/')) {
            $refs[] = $ref;
        }

        foreach ($value as $nested) {
            self::collectLocalRefs($nested, $refs);
        }
    }

    private static function getByJsonPointer(mixed $root, string $pointer): mixed
    {
        if (!str_starts_with($pointer, '#/')) {
            return null;
        }

        $segments = array_map(
            static fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
            explode('/', substr($pointer, 2)),
        );

        $current = $root;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /** @param array<string, mixed> $document */
    private static function assertLocalRefsResolve(array $document): void
    {
        $refs = [];
        self::collectLocalRefs($document, $refs);

        foreach ($refs as $ref) {
            self::assertNotNull(self::getByJsonPointer($document, $ref), "unresolved \$ref: {$ref}");
        }
    }

    // --- zodToOpenAPI ----------------------------------------------------------------------

    public function testStripsTheIdEmittedByZodRegistryConversionWithUri(): void
    {
        $schemaStore = ContentApiSchemaRegistry::createTestContentAPISchemaRegistry();
        $zodSchema = z::object(['name' => z::string()]);
        $probeRegistry = z::registry();
        $probeRegistry->add($zodSchema, ['id' => 'Probe']);

        $rawSchema = z::toJSONSchema($probeRegistry, [
            ...Zod::OPENAPI_SCHEMA_CONVERSION_OPTIONS,
            'uri' => static fn (string $id): string => Zod::toComponentsPath($id),
        ])['schemas']['Probe'];
        $schema = Zod::zodToOpenAPI($zodSchema, $schemaStore);

        self::assertSame('#/components/schemas/Probe', $rawSchema['$id']);
        self::assertIsArray($schema);
        self::assertArrayNotHasKey('$id', $schema);
        self::assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        self::assertSame('object', $schema['type']);
        self::assertSame(['type' => 'string'], $schema['properties']['name']);
    }

    public function testUsesOwnedComponentIdsForReferencesWithoutGeneratingSharedDefinitions(): void
    {
        $schemaStore = ContentApiSchemaRegistry::createTestContentAPISchemaRegistry();
        $articleSchema = z::object(['title' => z::string()]);
        $schemaStore->set('Article', $articleSchema);

        $schema = Zod::zodToOpenAPI(z::object(['article' => $articleSchema]), $schemaStore);

        self::assertIsArray($schema);
        self::assertSame(['$ref' => '#/components/schemas/Article'], $schema['properties']['article']);
        self::assertStringNotContainsString('__shared', (string) json_encode($schema));
    }

    // --- ComponentsWriter ------------------------------------------------------------------

    public function testStripsIdFromComponentSchemasWrittenFromTheStrapiOwnedRegistry(): void
    {
        $schemaStore = ContentApiSchemaRegistry::createTestContentAPISchemaRegistry();
        $registered = z::object(['title' => z::string()]);
        $schemaStore->set('CoverageProbeDocument', $registered);

        $context = (new DocumentContextFactory())->create(['strapi' => new StrapiConfigMock([], $schemaStore), 'routes' => []]);

        (new ComponentsWriter())->postProcess($context);

        $schema = $context->output->data['components']['schemas']['CoverageProbeDocument'] ?? null;
        self::assertIsArray($schema);
        self::assertArrayNotHasKey('$id', $schema);
        self::assertSame('object', $schema['type']);
        self::assertSame(['type' => 'string'], $schema['properties']['title']);
    }

    public function testEmitsNestedMetaIdSchemasFoundWhileConvertingTheOwnedStore(): void
    {
        $schemaStore = ContentApiSchemaRegistry::createTestContentAPISchemaRegistry();
        $nested = z::object(['value' => z::string()])->meta(['id' => 'OwnedNested']);
        $schemaStore->set('OwnedRoot', z::object(['nested' => $nested]));

        $context = (new DocumentContextFactory())->create(['strapi' => new StrapiConfigMock([], $schemaStore), 'routes' => []]);

        (new ComponentsWriter())->postProcess($context);

        $document = $context->output->data;
        self::assertSame('object', $document['components']['schemas']['OwnedRoot']['type']);
        self::assertSame(['$ref' => '#/components/schemas/OwnedNested'], $document['components']['schemas']['OwnedRoot']['properties']['nested']);
        self::assertSame('object', $document['components']['schemas']['OwnedNested']['type']);
        self::assertSame(['type' => 'string'], $document['components']['schemas']['OwnedNested']['properties']['value']);
        self::assertStringNotContainsString('__shared', (string) json_encode($document));
        self::assertLocalRefsResolve($document);
    }

    public function testMergesRouteHarvestedPluginSchemasSoNestedMetaIdRefsResolve(): void
    {
        $schemaStore = ContentApiSchemaRegistry::createTestContentAPISchemaRegistry();
        $pluginSchema = z::object(['value' => z::string()])->meta(['id' => 'PluginShared']);
        $response = z::object(['data' => $pluginSchema]);

        $documentContext = (new DocumentContextFactory())->create(['strapi' => new StrapiConfigMock([], $schemaStore), 'routes' => []]);

        $operationContext = (new OperationContextFactory())->create([
            'strapi' => $documentContext->strapi,
            'routes' => $documentContext->routes,
            'registries' => $documentContext->registries,
            'timer' => $documentContext->timer,
        ]);

        (new OperationResponsesAssembler())->assemble($operationContext, [
            'method' => 'GET',
            'path' => '/plugin',
            'handler' => '',
            'info' => ['type' => 'content-api'],
            'response' => $response,
        ]);

        $documentContext->output->data['paths'] = [
            '/plugin' => [
                'get' => $operationContext->output->data,
            ],
        ];

        (new ComponentsWriter())->postProcess($documentContext);

        $document = $documentContext->output->data;
        $pluginShared = $document['components']['schemas']['PluginShared'];
        $responseSchema = $document['paths']['/plugin']['get']['responses'][200]['content']['application/json']['schema'];

        self::assertSame('object', $pluginShared['type']);
        self::assertSame(['type' => 'string'], $pluginShared['properties']['value']);
        self::assertSame(['$ref' => '#/components/schemas/PluginShared'], $responseSchema['properties']['data']);
        self::assertStringNotContainsString('__shared', (string) json_encode($document));
        self::assertLocalRefsResolve($document);
    }
}
