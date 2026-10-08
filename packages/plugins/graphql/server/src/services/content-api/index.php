<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\ContentApi;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;
use GraphQL\Utils\SchemaPrinter;
use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\PluginDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Builders\Builders;
use Strapi\Plugin\Graphql\Services\Builders\BuildersInstance;
use Strapi\Plugin\Graphql\Services\Constants;
use Strapi\Plugin\Graphql\Services\ContentApi\RegisterFunctions\CollectionType;
use Strapi\Plugin\Graphql\Services\ContentApi\RegisterFunctions\Component;
use Strapi\Plugin\Graphql\Services\ContentApi\RegisterFunctions\DynamicZones;
use Strapi\Plugin\Graphql\Services\ContentApi\RegisterFunctions\Enums;
use Strapi\Plugin\Graphql\Services\ContentApi\RegisterFunctions\Filters;
use Strapi\Plugin\Graphql\Services\ContentApi\RegisterFunctions\Inputs;
use Strapi\Plugin\Graphql\Services\ContentApi\RegisterFunctions\Internals;
use Strapi\Plugin\Graphql\Services\ContentApi\RegisterFunctions\Polymorphic;
use Strapi\Plugin\Graphql\Services\ContentApi\RegisterFunctions\Scalars;
use Strapi\Plugin\Graphql\Services\ContentApi\RegisterFunctions\SingleType;
use Strapi\Plugin\Graphql\Services\Extension\Extension;
use Strapi\Plugin\Graphql\Services\TypeRegistry;
use Strapi\Types\Schema\Schema as ContentTypeSchema;

/**
 * Port of server/src/services/content-api/index.ts: builds the content API schema.
 *
 * nexus' `makeSchema`, @graphql-tools' `mergeSchemas` / `addResolversToSchema` run as one
 * {@see Nexus::makeSchema()} call (nexus types, SDL `typeDefs`, `resolvers` and plugins);
 * `pruneSchema` keeps the types reachable from the root operation types.
 */
final class ContentApi
{
    // Type Registry
    private ?TypeRegistry $registry = null;

    // Builders Instances
    private ?BuildersInstance $builders = null;

    /** @var array<string, true> Cached set of built-in query fields (populated at bootstrap) */
    private array $builtInQueryFields = [];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    private function extensionService(): Extension
    {
        $extension = $this->strapi->plugin('graphql')->service('extension');
        \assert($extension instanceof Extension);

        return $extension;
    }

    public function buildSchema(): Schema
    {
        $graphql = $this->strapi->plugin('graphql');
        $isShadowCRUDEnabled = (bool) $graphql->config('shadowCRUD');

        // Create a new empty type registry
        $typeRegistry = $graphql->service('type-registry');
        \assert($typeRegistry instanceof TypeRegistry);
        $registry = $typeRegistry->new();
        $this->registry = $registry;

        // Reset the builders instances associated to the
        // content-api, and link the new type registry
        $buildersService = $graphql->service('builders');
        \assert($buildersService instanceof Builders);
        $this->builders = $buildersService->new('content-api', $this->registry);

        Scalars::registerScalars(['registry' => $this->registry, 'strapi' => $this->strapi]);
        Internals::registerInternals(['registry' => $this->registry, 'strapi' => $this->strapi]);

        if ($isShadowCRUDEnabled) {
            $this->shadowCRUD();
        }

        // Build a merged schema from both Nexus types & SDL type definitions, with the
        // extension's resolvers and nexus plugins.
        //
        // The extension configuration for the content API is generated after the shadow CRUD
        // schema's generation, so that configurations created during types definitions
        // can be registered before being used in the wrap resolvers operation
        $schema = $this->buildMergedSchema($registry);

        // Whether to generate artifacts (GraphQL schema, TS types definitions) or not.
        // By default, we generate artifacts only on development environment
        $this->generateArtifacts($schema);

        // Generate the extension configuration for the content API.
        $extension = $this->extensionService()->generate(['typeRegistry' => $registry]);

        // Wrap resolvers if needed (auth, middlewares, policies...) as configured in the extension
        $wrappedSchema = WrapResolvers::wrapResolvers(['schema' => $schema, 'strapi' => $this->strapi, 'extension' => $extension]);

        // Prune schema, remove unused types
        // eg: removes registered subscriptions if they're disabled in the config)
        return self::pruneSchema($wrappedSchema);
    }

    /**
     * nexus' artifacts generation (`shouldGenerateArtifacts` / `outputs.schema`): the SDL of the
     * schema, sorted, behind nexus' header. `outputs.typegen` (TypeScript types) has no PHP
     * equivalent and is not generated.
     */
    private function generateArtifacts(Schema $schema): void
    {
        $graphql = $this->strapi->plugin('graphql');
        $currentEnv = $this->strapi->config()->get('environment');

        if (!$graphql->config('generateArtifacts', $currentEnv === 'development')) {
            return;
        }

        $output = $graphql->config('artifacts.schema', false);
        if ($output === true) {
            $output = (getcwd() ?: '.') . DIRECTORY_SEPARATOR . 'schema.graphql';
        }
        if (!is_string($output) || $output === '') {
            return;
        }
        if (!str_starts_with($output, '/') && preg_match('#^[A-Za-z]:[/\\\\]#', $output) !== 1) {
            throw new \RuntimeException("Expected an absolute path to output the Nexus schema, saw {$output}");
        }

        $content = "### This file was generated by Nexus Schema\n### Do not make changes to this file directly\n\n\n"
            . SchemaPrinter::doPrint($schema, ['sortTypes' => true, 'sortFields' => true, 'sortArguments' => true, 'sortEnumValues' => true]);

        $existing = is_file($output) ? (string) file_get_contents($output) : '';
        if ($content !== $existing) {
            if (!is_dir(dirname($output))) {
                mkdir(dirname($output), 0777, true);
            }
            file_put_contents($output, $content);
        }
    }

    /** @return array<string, true> */
    public function getBuiltInQueryFields(): array
    {
        return $this->builtInQueryFields;
    }

    public function isBuiltInQueryField(string $fieldName): bool
    {
        return isset($this->builtInQueryFields[$fieldName]) || str_ends_with($fieldName, '_connection');
    }

    private function buildMergedSchema(TypeRegistry $registry): Schema
    {
        $definitions = array_map(static fn (array $def): mixed => $def['definition'], $registry->definitions());

        // Capture the built-in (shadow CRUD) query fields from a schema built with only the
        // registry's own types, before any extension-registered types/typeDefs are merged in,
        // so isBuiltInQueryField can tell shadow CRUD fields apart from custom extension resolvers.
        $shadowCRUDSchema = Nexus::makeSchema(['types' => [$definitions]]);
        $shadowCRUDQueryFields = $shadowCRUDSchema->getQueryType()?->getFields() ?? [];
        $this->builtInQueryFields = array_fill_keys(array_keys($shadowCRUDQueryFields), true);

        // Here we extract types, plugins & typeDefs from a temporary generated
        // extension since there won't be any addition allowed after schemas generation
        $extension = $this->extensionService()->generate(['typeRegistry' => $registry]);

        /** @var list<PluginDef> $plugins */
        $plugins = array_values(array_filter($extension['plugins'], static fn (mixed $plugin): bool => $plugin instanceof PluginDef));

        // Nexus schema built with user-defined & shadow CRUD auto generated Nexus types, merged
        // with the SDL type definitions and the extension's resolvers
        return Nexus::makeSchema([
            'types' => [$definitions, $extension['types']],
            'typeDefs' => $extension['typeDefs'],
            'resolvers' => $extension['resolvers'],
            'plugins' => $plugins,
        ]);
    }

    private function shadowCRUD(): void
    {
        $extensionService = $this->extensionService();

        // Get every content type & component defined in Strapi
        $contentTypes = [
            ...array_values($this->strapi->components()),
            ...array_values($this->strapi->contentTypes()),
        ];

        // Disable Shadow CRUD for admin content types
        foreach ($contentTypes as $contentType) {
            if (str_starts_with($contentType->uid, 'admin::')) {
                $extensionService->shadowCRUD($contentType->uid)->disable();
            }
        }

        $contentTypesWithShadowCRUD = array_values(array_filter(
            $contentTypes,
            static fn (ContentTypeSchema $ct): bool => $extensionService->shadowCRUD($ct->uid)->isEnabled(),
        ));

        // Generate and register definitions for every content type
        $this->registerAPITypes($contentTypesWithShadowCRUD);

        // Generate and register polymorphic types' definitions
        $this->registerMorphTypes($contentTypesWithShadowCRUD);
    }

    /**
     * Register needed GraphQL types for every content type
     *
     * @param list<ContentTypeSchema> $contentTypes
     */
    private function registerAPITypes(array $contentTypes): void
    {
        \assert($this->registry !== null && $this->builders !== null);

        foreach ($contentTypes as $contentType) {
            $modelType = $contentType->modelType;

            $registerOptions = ['registry' => $this->registry, 'strapi' => $this->strapi, 'builders' => $this->builders];

            // Generate various types associated to the content type
            // (enums, dynamic-zones, filters, inputs...)
            Enums::registerEnumsDefinition($contentType, $registerOptions);
            DynamicZones::registerDynamicZonesDefinition($contentType, $registerOptions);
            Filters::registerFiltersDefinition($contentType, $registerOptions);
            Inputs::registerInputsDefinition($contentType, $registerOptions);

            // Generate & register component's definition
            if ($modelType === 'component') {
                Component::registerComponent($contentType, $registerOptions);
                continue;
            }

            $kind = $contentType->kind;

            // Generate & register single type's definition
            if ($kind === 'singleType') {
                SingleType::registerSingleType($contentType, $registerOptions);
            }

            // Generate & register collection type's definition
            elseif ($kind === 'collectionType') {
                CollectionType::registerCollectionType($contentType, $registerOptions);
            }
        }
    }

    /** @param list<ContentTypeSchema> $contentTypes */
    private function registerMorphTypes(array $contentTypes): void
    {
        \assert($this->registry !== null && $this->builders !== null);

        // Create & register a union type that includes every type or component registered
        $genericMorphType = $this->builders->buildGenericMorphDefinition();
        $this->registry->register(Constants::GENERIC_MORPH_TYPENAME, $genericMorphType, ['kind' => Constants::KINDS['morph']]);

        foreach ($contentTypes as $contentType) {
            Polymorphic::registerPolymorphicContentType($contentType, ['registry' => $this->registry, 'strapi' => $this->strapi]);
        }
    }

    /**
     * @graphql-tools/utils `pruneSchema`: keep the types reachable from the root operation types
     * (and the object types implementing a reachable interface); mutation and subscription types
     * without fields are dropped.
     */
    private static function pruneSchema(Schema $schema): Schema
    {
        $nonEmpty = static fn (?ObjectType $type): ?ObjectType => $type !== null && $type->getFields() !== [] ? $type : null;

        $pruned = new Schema([
            'query' => $schema->getQueryType(),
            'mutation' => $nonEmpty($schema->getMutationType()),
            'subscription' => $nonEmpty($schema->getSubscriptionType()),
            'directives' => $schema->getDirectives(),
        ]);

        $implementations = [];
        $reachable = $pruned->getTypeMap();
        foreach ($schema->getTypeMap() as $name => $type) {
            if (!isset($reachable[$name]) && $type instanceof ObjectType) {
                foreach ($type->getInterfaces() as $interface) {
                    if (isset($reachable[$interface->name])) {
                        $implementations[] = $type;
                        break;
                    }
                }
            }
        }

        if ($implementations === []) {
            return $pruned;
        }

        return new Schema([
            'query' => $pruned->getQueryType(),
            'mutation' => $pruned->getMutationType(),
            'subscription' => $pruned->getSubscriptionType(),
            'directives' => $schema->getDirectives(),
            'types' => $implementations,
        ]);
    }
}
