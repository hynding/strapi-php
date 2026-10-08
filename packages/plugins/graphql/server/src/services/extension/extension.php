<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Extension;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;

/**
 * Port of server/src/services/extension/extension.ts: the `extension` service other plugins and
 * applications use to customize the content API schema.
 *
 * - `shadowCRUD(uid)` enables / disables the generated types, queries, mutations, actions, fields;
 * - `use(configuration)` registers `{ types, typeDefs, resolvers, resolversConfig, plugins }` or a
 *   factory `fn (array{strapi, nexus, typeRegistry}) => configuration` evaluated at schema build time.
 *
 * @phpstan-type Configuration array{types?: mixed, typeDefs?: mixed, resolvers?: mixed, resolversConfig?: mixed, plugins?: mixed}
 * @phpstan-type ExtensionState array{types: list<mixed>, typeDefs: list<string>, resolvers: array<string, mixed>, resolversConfig: array<string, mixed>, plugins: list<mixed>}
 */
final class Extension
{
    /** @var list<array<string, mixed>|callable> */
    private array $configs = [];

    private readonly ShadowCrudManager $shadowCRUDManager;

    public function __construct(private readonly Strapi $strapi)
    {
        $this->shadowCRUDManager = new ShadowCrudManager();
    }

    /** @return ExtensionState */
    private static function getDefaultState(): array
    {
        return [
            'types' => [],
            'typeDefs' => [],
            'resolvers' => [],
            'resolversConfig' => [],
            'plugins' => [],
        ];
    }

    public function shadowCRUD(string $uid): ShadowCrudContentType
    {
        return ($this->shadowCRUDManager)($uid);
    }

    /**
     * Register a new extension configuration
     *
     * @param array<string, mixed>|callable $configuration
     */
    public function use(array|callable $configuration): self
    {
        $this->configs[] = $configuration;

        return $this;
    }

    /**
     * Convert the registered configuration into a single extension object & return it
     *
     * @param array{typeRegistry?: mixed} $options
     * @return ExtensionState
     */
    public function generate(array $options = []): array
    {
        $typeRegistry = $options['typeRegistry'] ?? null;

        $resolveConfig = function (array|callable $config) use ($typeRegistry): array {
            if (is_callable($config)) {
                $resolved = $config(['strapi' => $this->strapi, 'nexus' => new Nexus(), 'typeRegistry' => $typeRegistry]);

                return is_array($resolved) ? $resolved : [];
            }

            return $config;
        };

        // Evaluate & merge every registered configuration object, then return the result
        $acc = self::getDefaultState();
        foreach ($this->configs as $configuration) {
            $resolved = $resolveConfig($configuration);
            $types = $resolved['types'] ?? null;
            $typeDefs = $resolved['typeDefs'] ?? null;
            $resolvers = $resolved['resolvers'] ?? null;
            $resolversConfig = $resolved['resolversConfig'] ?? null;
            $plugins = $resolved['plugins'] ?? null;

            // Register type definitions
            if (is_string($typeDefs)) {
                $acc['typeDefs'][] = $typeDefs;
            }

            // Register nexus types
            if (is_array($types) && array_is_list($types)) {
                foreach ($types as $type) {
                    $acc['types'][] = $type;
                }
            }

            // Register nexus plugins
            if (is_array($plugins) && array_is_list($plugins)) {
                foreach ($plugins as $plugin) {
                    $acc['plugins'][] = $plugin;
                }
            }

            // Register resolvers
            if (is_array($resolvers)) {
                $acc['resolvers'] = self::merge($acc['resolvers'], $resolvers);
            }

            // Register resolvers configuration
            if (is_array($resolversConfig)) {
                // TODO: smarter merge for auth, middlewares & policies
                $acc['resolversConfig'] = self::merge($resolversConfig, $acc['resolversConfig']);
            }
        }

        return $acc;
    }

    /**
     * lodash `merge(object, source)`: plain objects and arrays are merged recursively, by key /
     * index, other source values replace the object's.
     *
     * @param array<array-key, mixed> $object
     * @param array<array-key, mixed> $source
     * @return array<array-key, mixed>
     */
    public static function merge(array $object, array $source): array
    {
        foreach ($source as $key => $value) {
            if (is_array($value) && is_array($object[$key] ?? null)) {
                $object[$key] = self::merge($object[$key], $value);
            } else {
                $object[$key] = $value;
            }
        }

        return $object;
    }
}
