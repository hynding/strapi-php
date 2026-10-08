<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib\Nexus;

use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Schema;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ArgDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\EnumTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ExtendTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\InputObjectTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\InterfaceTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ObjectTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\PluginDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ScalarTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\UnionTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\WrappedTypeDef;

/**
 * The part of the `nexus` (1.3) API the plugin and its extensions use, on top of
 * webonyx/graphql-php. Upstream hands the `nexus` module to extension factories
 * (`extension.use(({ nexus }) => ...)`); here they receive an instance of this class, whose
 * methods are also callable statically (`Nexus::objectType([...])`).
 *
 * Type configs are the arrays nexus takes (`['name' => ..., 'definition' => fn ($t) => ...]`);
 * definition blocks are {@see Blocks\OutputDefinitionBlock} / {@see Blocks\InputDefinitionBlock}.
 */
final class Nexus
{
    /** @param array<string, mixed> $config */
    public static function objectType(array $config): ObjectTypeDef
    {
        return new ObjectTypeDef($config);
    }

    /** @param array<string, mixed> $config */
    public static function inputObjectType(array $config): InputObjectTypeDef
    {
        return new InputObjectTypeDef($config);
    }

    /** @param array<string, mixed> $config */
    public static function interfaceType(array $config): InterfaceTypeDef
    {
        return new InterfaceTypeDef($config);
    }

    /** @param array<string, mixed> $config */
    public static function unionType(array $config): UnionTypeDef
    {
        return new UnionTypeDef($config);
    }

    /** @param array<string, mixed> $config */
    public static function enumType(array $config): EnumTypeDef
    {
        return new EnumTypeDef($config);
    }

    /** @param array<string, mixed> $config */
    public static function scalarType(array $config): ScalarTypeDef
    {
        return new ScalarTypeDef($config);
    }

    /** `asNexusMethod(scalar, methodName)`: the scalar plus a `t.<methodName>()` shorthand. */
    public static function asNexusMethod(ScalarType|ScalarTypeDef $scalar, string $methodName): ScalarTypeDef
    {
        if ($scalar instanceof ScalarTypeDef) {
            return new ScalarTypeDef([...$scalar->config, 'asNexusMethod' => $methodName], $scalar->scalar);
        }

        return new ScalarTypeDef(['name' => $scalar->name, 'description' => $scalar->description, 'asNexusMethod' => $methodName], $scalar);
    }

    /** @param array<string, mixed> $config */
    public static function extendType(array $config): ExtendTypeDef
    {
        return new ExtendTypeDef($config);
    }

    /** @param array<string, mixed> $config */
    public static function extendInputType(array $config): ExtendTypeDef
    {
        return new ExtendTypeDef($config, true);
    }

    /**
     * `queryField(name, config)`: `extendType({ type: 'Query', definition: t => t.field(name, config) })`
     *
     * @param array<string, mixed>|callable(): array<string, mixed> $config
     */
    public static function queryField(string $name, array|callable $config): ExtendTypeDef
    {
        return self::rootField('Query', $name, $config);
    }

    /**
     * `mutationField(name, config)`
     *
     * @param array<string, mixed>|callable(): array<string, mixed> $config
     */
    public static function mutationField(string $name, array|callable $config): ExtendTypeDef
    {
        return self::rootField('Mutation', $name, $config);
    }

    /** @param array<string, mixed>|callable(): array<string, mixed> $config */
    private static function rootField(string $type, string $name, array|callable $config): ExtendTypeDef
    {
        return new ExtendTypeDef([
            'type' => $type,
            'definition' => static function (Blocks\OutputDefinitionBlock $t) use ($name, $config): void {
                $resolved = is_callable($config) ? $config() : $config;
                $t->field($name, is_array($resolved) ? $resolved : []);
            },
        ]);
    }

    public static function nonNull(mixed $type): WrappedTypeDef
    {
        return new WrappedTypeDef(WrappedTypeDef::NON_NULL, $type);
    }

    public static function nullable(mixed $type): WrappedTypeDef
    {
        return new WrappedTypeDef(WrappedTypeDef::NULL, $type);
    }

    public static function list(mixed $type): WrappedTypeDef
    {
        return new WrappedTypeDef(WrappedTypeDef::LIST, $type);
    }

    /** @param array<string, mixed> $config */
    public static function arg(array $config): ArgDef
    {
        return new ArgDef($config);
    }

    /** @param array<string, mixed> $config */
    public static function stringArg(array $config = []): ArgDef
    {
        return new ArgDef([...$config, 'type' => 'String']);
    }

    /** @param array<string, mixed> $config */
    public static function intArg(array $config = []): ArgDef
    {
        return new ArgDef([...$config, 'type' => 'Int']);
    }

    /** @param array<string, mixed> $config */
    public static function floatArg(array $config = []): ArgDef
    {
        return new ArgDef([...$config, 'type' => 'Float']);
    }

    /** @param array<string, mixed> $config */
    public static function booleanArg(array $config = []): ArgDef
    {
        return new ArgDef([...$config, 'type' => 'Boolean']);
    }

    /** @param array<string, mixed> $config */
    public static function idArg(array $config = []): ArgDef
    {
        return new ArgDef([...$config, 'type' => 'ID']);
    }

    /** @param array<string, mixed> $config */
    public static function plugin(array $config): PluginDef
    {
        return new PluginDef($config);
    }

    /**
     * `makeSchema({ types, plugins })`, plus `typeDefs` (SDL) and `resolvers` maps (see {@see Builder}).
     *
     * @param array{types?: mixed, typeDefs?: list<string>, resolvers?: array<string, mixed>, plugins?: list<PluginDef>} $config
     */
    public static function makeSchema(array $config): Schema
    {
        return Builder::makeSchema($config);
    }
}
