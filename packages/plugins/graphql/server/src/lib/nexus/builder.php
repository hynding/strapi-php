<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib\Nexus;

use GraphQL\Language\AST\DirectiveNode;
use GraphQL\Language\AST\EnumTypeDefinitionNode;
use GraphQL\Language\AST\EnumTypeExtensionNode;
use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\InputObjectTypeDefinitionNode;
use GraphQL\Language\AST\InputObjectTypeExtensionNode;
use GraphQL\Language\AST\InputValueDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeExtensionNode;
use GraphQL\Language\AST\ListTypeNode;
use GraphQL\Language\AST\NamedTypeNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NodeList;
use GraphQL\Language\AST\NonNullTypeNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\ObjectTypeExtensionNode;
use GraphQL\Language\AST\ScalarTypeDefinitionNode;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use GraphQL\Language\AST\UnionTypeExtensionNode;
use GraphQL\Language\AST\ValueNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InputType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\NullableType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\OutputType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\InputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\UnionDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ArgDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\EnumTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ExtendTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\InputObjectTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\InterfaceTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\NamedTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ObjectTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\PluginDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ScalarTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\UnionTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\WrappedTypeDef;

/**
 * The schema builder behind {@see Nexus::makeSchema()}: what nexus' `SchemaBuilder` does for the
 * plugin, plus the two @graphql-tools steps upstream chains with it (`mergeSchemas({ typeDefs })`
 * for SDL type definitions, `addResolversToSchema({ resolvers })` for resolver maps) since the
 * three run on the same type set here.
 *
 * - `types`: nexus definitions, graphql named types, nested lists / maps of them (walked like
 *   nexus' `addTypes`), `extendType` definitions merged into their target type;
 * - `typeDefs`: SDL strings; a type that already exists is extended (graphql-tools merges type
 *   definitions with the same name), a new one is added;
 * - `resolvers`: `{ Type: { field: resolve | { resolve } }, Scalar: GraphQLScalarType, Enum: { VALUE: internal } }`
 *   with `__resolveType` / `__isTypeOf`;
 * - `plugins`: `onAddOutputField`, `onAddInputField`, `onAddArg` hooks.
 *
 * Wrapping follows nexus 1.3 with `nonNullDefaults: { input: false, output: false }`.
 *
 * @phpstan-type FieldRecord array<string, mixed>
 */
final class Builder
{
    private const array ROOT_TYPES = ['Query', 'Mutation', 'Subscription'];

    /** @var array<string, NamedTypeDef|Type> */
    private array $defs = [];

    /** @var array<string, list<callable>> object / interface type extensions */
    private array $extensions = [];

    /** @var array<string, list<callable>> input object type extensions */
    private array $inputExtensions = [];

    /** @var array<string, list<string>> interfaces implemented by an SDL object type */
    private array $interfaces = [];

    /** @var array<string, string> `t.<method>` => scalar type name */
    private array $dynamicMethods = [];

    /** @var array<string, array<string, FieldRecord>> */
    private array $outputFields = [];

    /** @var array<string, array<string, FieldRecord>> */
    private array $inputFields = [];

    /** @var array<string, list<string>> */
    private array $unionMembers = [];

    /** @var array<string, Type&NamedType> */
    private array $built = [];

    /**
     * @param list<PluginDef> $plugins
     * @param array<string, mixed> $resolvers
     */
    private function __construct(private readonly array $plugins, private readonly array $resolvers)
    {
    }

    /**
     * @param array{types?: mixed, typeDefs?: list<string>, resolvers?: array<string, mixed>, plugins?: list<PluginDef>} $config
     */
    public static function makeSchema(array $config): Schema
    {
        $builder = new self($config['plugins'] ?? [], $config['resolvers'] ?? []);
        $builder->addTypes($config['types'] ?? []);
        foreach ($config['typeDefs'] ?? [] as $typeDefs) {
            $builder->addTypeDefs($typeDefs);
        }
        $builder->collect();

        return $builder->buildSchema();
    }

    // ------------------------------------------------------------------------------------------
    // Type collection

    private function addTypes(mixed $types): void
    {
        if ($types === null || is_string($types) || is_scalar($types)) {
            return;
        }

        if ($types instanceof NamedTypeDef || ($types instanceof Type && $types instanceof NamedType)) {
            $this->addType($types);

            return;
        }

        if ($types instanceof ExtendTypeDef) {
            if ($types->input) {
                $this->inputExtensions[$types->type][] = $types->definition;
            } else {
                $this->extensions[$types->type][] = $types->definition;
            }

            return;
        }

        if ($types instanceof WrappedTypeDef) {
            $this->addTypes($types->ofType);

            return;
        }

        if ($types instanceof ArgDef) {
            $this->addTypes($types->config['type']);

            return;
        }

        if (is_iterable($types)) {
            foreach ($types as $type) {
                $this->addTypes($type);
            }
        }
    }

    private function addType(NamedTypeDef|Type $type): void
    {
        $name = $type instanceof NamedTypeDef ? $type->name : ($type instanceof NamedType ? $type->name() : '');
        if ($name === '' || in_array($name, ['String', 'Int', 'Float', 'Boolean', 'ID'], true)) {
            return;
        }

        $existing = $this->defs[$name] ?? null;
        if ($existing !== null && $existing !== $type) {
            // nexus keeps the first definition of a name and ignores identical re-registrations;
            // two different definitions with one name is a schema error
            if (!($existing instanceof ScalarTypeDef && $type instanceof ScalarTypeDef && $existing->scalar !== null && $existing->scalar === $type->scalar)) {
                throw new \RuntimeException("Duplicate type definition for \"{$name}\"");
            }

            return;
        }

        $this->defs[$name] = $type;

        if ($type instanceof ScalarTypeDef && $type->nexusMethod() !== null) {
            $this->dynamicMethods[(string) $type->nexusMethod()] = $name;
        }
    }

    /** SDL type definitions (`mergeSchemas({ typeDefs })`). */
    private function addTypeDefs(string $typeDefs): void
    {
        $document = Parser::parse($typeDefs, ['noLocation' => true]);

        foreach ($document->definitions as $node) {
            if ($node instanceof ObjectTypeDefinitionNode || $node instanceof ObjectTypeExtensionNode || $node instanceof InterfaceTypeDefinitionNode || $node instanceof InterfaceTypeExtensionNode) {
                $name = $node->name->value;
                $definition = function (OutputDefinitionBlock $t) use ($node): void {
                    foreach ($node->fields as $field) {
                        $t->field($field->name->value, $this->fieldConfigFromAst($field));
                    }
                };
                $isInterface = $node instanceof InterfaceTypeDefinitionNode || $node instanceof InterfaceTypeExtensionNode;
                if (($node instanceof ObjectTypeDefinitionNode || $node instanceof ObjectTypeExtensionNode) && count($node->interfaces) > 0) {
                    foreach ($node->interfaces as $interface) {
                        $this->interfaces[$name][] = $interface->name->value;
                    }
                }

                if (isset($this->defs[$name]) || in_array($name, self::ROOT_TYPES, true)) {
                    $this->extensions[$name][] = $definition;
                } else {
                    $config = ['name' => $name, 'definition' => $definition, 'description' => self::descriptionOf($node)];
                    $this->addType($isInterface ? new InterfaceTypeDef($config) : new ObjectTypeDef($config));
                }

                continue;
            }

            if ($node instanceof InputObjectTypeDefinitionNode || $node instanceof InputObjectTypeExtensionNode) {
                $name = $node->name->value;
                $definition = function (InputDefinitionBlock $t) use ($node): void {
                    foreach ($node->fields as $field) {
                        $t->field($field->name->value, $this->inputValueConfigFromAst($field));
                    }
                };

                if (isset($this->defs[$name])) {
                    $this->inputExtensions[$name][] = $definition;
                } else {
                    $this->addType(new InputObjectTypeDef(['name' => $name, 'definition' => $definition, 'description' => self::descriptionOf($node)]));
                }

                continue;
            }

            if ($node instanceof EnumTypeDefinitionNode || $node instanceof EnumTypeExtensionNode) {
                $members = [];
                foreach ($node->values as $value) {
                    $members[] = [
                        'name' => $value->name->value,
                        'value' => $value->name->value,
                        'description' => self::descriptionOf($value),
                        'deprecation' => self::deprecationOf($value->directives),
                    ];
                }
                $name = $node->name->value;
                if (!isset($this->defs[$name])) {
                    $this->addType(new EnumTypeDef(['name' => $name, 'members' => $members, 'description' => self::descriptionOf($node)]));
                }

                continue;
            }

            if ($node instanceof ScalarTypeDefinitionNode) {
                $name = $node->name->value;
                if (!isset($this->defs[$name])) {
                    $this->addType(new ScalarTypeDef(['name' => $name, 'description' => self::descriptionOf($node)]));
                }

                continue;
            }

            if ($node instanceof UnionTypeDefinitionNode || $node instanceof UnionTypeExtensionNode) {
                $name = $node->name->value;
                $members = [];
                foreach ($node->types as $type) {
                    $members[] = $type->name->value;
                }
                if (isset($this->defs[$name])) {
                    $this->unionMembers[$name] = [...($this->unionMembers[$name] ?? []), ...$members];
                } else {
                    $this->addType(new UnionTypeDef([
                        'name' => $name,
                        'description' => self::descriptionOf($node),
                        'definition' => static function (UnionDefinitionBlock $t) use ($members): void {
                            $t->members(...$members);
                        },
                    ]));
                }
            }
            // schema definitions and directive definitions are not supported (nexus builds the roots)
        }
    }

    private static function descriptionOf(Node $node): ?string
    {
        $description = property_exists($node, 'description') ? $node->description : null;

        return $description instanceof StringValueNode ? $description->value : null;
    }

    /** @param NodeList<DirectiveNode> $directives */
    private static function deprecationOf(NodeList $directives): ?string
    {
        foreach ($directives as $directive) {
            if ($directive->name->value !== 'deprecated') {
                continue;
            }
            foreach ($directive->arguments as $argument) {
                if ($argument->name->value === 'reason' && $argument->value instanceof StringValueNode) {
                    return $argument->value->value;
                }
            }

            return 'No longer supported';
        }

        return null;
    }

    private static function typeRefFromAst(Node $node): string|WrappedTypeDef
    {
        if ($node instanceof NonNullTypeNode) {
            return new WrappedTypeDef(WrappedTypeDef::NON_NULL, self::typeRefFromAst($node->type));
        }
        if ($node instanceof ListTypeNode) {
            return new WrappedTypeDef(WrappedTypeDef::LIST, self::typeRefFromAst($node->type));
        }
        if ($node instanceof NamedTypeNode) {
            return $node->name->value;
        }

        throw new \RuntimeException('Unsupported type node ' . $node->kind);
    }

    /** @return array<string, mixed> */
    private function fieldConfigFromAst(FieldDefinitionNode $field): array
    {
        $args = [];
        foreach ($field->arguments as $argument) {
            $args[$argument->name->value] = new ArgDef($this->inputValueConfigFromAst($argument));
        }

        return [
            'type' => self::typeRefFromAst($field->type),
            'args' => $args,
            'description' => self::descriptionOf($field),
            'deprecation' => self::deprecationOf($field->directives),
        ];
    }

    /** @return array<string, mixed> */
    private function inputValueConfigFromAst(InputValueDefinitionNode $value): array
    {
        $config = [
            'type' => self::typeRefFromAst($value->type),
            'description' => self::descriptionOf($value),
        ];
        if ($value->defaultValue !== null) {
            $config['defaultAst'] = $value->defaultValue;
        }

        return $config;
    }

    /**
     * Runs every definition (nexus does it in `makeSchema`), registering the named types fields
     * reference, until no type is left unprocessed.
     */
    private function collect(): void
    {
        foreach (self::ROOT_TYPES as $root) {
            if (isset($this->extensions[$root]) && !isset($this->defs[$root])) {
                $this->addType(new ObjectTypeDef(['name' => $root, 'definition' => static function (): void {
                }]));
            }
        }

        // nexus' `missingType('Query')`: a schema always has a query type
        if (!isset($this->defs['Query'])) {
            $this->addType(new ObjectTypeDef(['name' => 'Query', 'definition' => static function (OutputDefinitionBlock $t): void {
                $t->nonNull->boolean('ok', ['resolve' => static fn (): bool => true]);
            }]));
        }

        $processed = [];
        do {
            $pending = array_diff_key($this->defs, $processed);
            foreach ($pending as $name => $def) {
                $processed[$name] = true;

                if ($def instanceof ObjectTypeDef || $def instanceof InterfaceTypeDef) {
                    $this->collectOutputFields($name, $def);
                } elseif ($def instanceof InputObjectTypeDef) {
                    $this->collectInputFields($name, $def);
                } elseif ($def instanceof UnionTypeDef) {
                    $block = new UnionDefinitionBlock();
                    $definition = $def->config['definition'] ?? null;
                    if (is_callable($definition)) {
                        $definition($block);
                    }
                    $members = [];
                    foreach ($block->getMembers() as $member) {
                        if ($member instanceof NamedTypeDef) {
                            $this->addType($member);
                            $member = $member->name;
                        }
                        $members[] = $member;
                    }
                    $this->unionMembers[$name] = [...$members, ...($this->unionMembers[$name] ?? [])];
                }
            }
        } while ($pending !== []);

        foreach (array_keys($this->extensions) as $name) {
            if (!isset($this->defs[$name])) {
                throw new \RuntimeException("Cannot extend the type \"{$name}\": it is not defined");
            }
        }
    }

    private function collectOutputFields(string $name, ObjectTypeDef|InterfaceTypeDef $def): void
    {
        $fields = [];
        $block = new OutputDefinitionBlock(
            $name,
            static function (array $field) use (&$fields): void {
                $fields[(string) $field['name']] = $field;
            },
            $this->dynamicMethods,
            [],
            $def instanceof ObjectTypeDef ? function (string $interface) use ($name): void {
                if (!in_array($interface, $this->interfaces[$name] ?? [], true)) {
                    $this->interfaces[$name][] = $interface;
                }
            } : null,
        );

        $definition = $def->config['definition'] ?? null;
        if (is_callable($definition)) {
            $definition($block);
        }
        foreach ($this->extensions[$name] ?? [] as $extension) {
            $extension($block);
        }

        $typeResolvers = is_array($this->resolvers[$name] ?? null) ? $this->resolvers[$name] : [];

        $final = [];
        foreach ($fields as $fieldName => $field) {
            // addResolversToSchema
            $resolver = $typeResolvers[$fieldName] ?? null;
            if (is_callable($resolver)) {
                $field['resolve'] = $resolver;
            } elseif (is_array($resolver)) {
                foreach (['resolve', 'subscribe', 'description', 'deprecation'] as $key) {
                    if (array_key_exists($key, $resolver)) {
                        $field[$key] = $resolver[$key];
                    }
                }
            }

            $field = $this->runHook('onAddOutputField', $field);
            $field['args'] = $this->normalizeArgs($field, $name);

            $this->discover($field['type'] ?? null);
            foreach ($field['args'] as $arg) {
                $this->discover($arg->config['type']);
            }

            $final[(string) $field['name']] = $field;
        }

        $this->outputFields[$name] = $final;
    }

    private function collectInputFields(string $name, InputObjectTypeDef $def): void
    {
        $fields = [];
        $block = new InputDefinitionBlock(
            $name,
            static function (array $field) use (&$fields): void {
                $fields[(string) $field['name']] = $field;
            },
            $this->dynamicMethods,
        );

        $definition = $def->config['definition'] ?? null;
        if (is_callable($definition)) {
            $definition($block);
        }
        foreach ($this->inputExtensions[$name] ?? [] as $extension) {
            $extension($block);
        }

        $final = [];
        foreach ($fields as $field) {
            $field = $this->runHook('onAddInputField', $field);
            $this->discover($field['type'] ?? null);
            $final[(string) $field['name']] = $field;
        }

        $this->inputFields[$name] = $final;
    }

    /**
     * nexus' `normalizeArgWrapping` + `onAddArg`.
     *
     * @param FieldRecord $field
     * @return array<string, ArgDef>
     */
    private function normalizeArgs(array $field, string $parentType): array
    {
        $args = is_array($field['args'] ?? null) ? $field['args'] : [];
        $out = [];
        foreach ($args as $argName => $arg) {
            $argDef = self::normalizeArgWrapping($arg);
            $config = $this->runHook('onAddArg', [
                ...$argDef->config,
                'fieldName' => $field['name'],
                'argName' => $argName,
                'parentType' => $parentType,
                'configFor' => 'arg',
            ]);
            $out[(string) $argName] = new ArgDef($config);
        }

        return $out;
    }

    private static function normalizeArgWrapping(mixed $arg): ArgDef
    {
        if ($arg instanceof ArgDef) {
            return $arg;
        }

        if ($arg instanceof WrappedTypeDef) {
            [$named, $wrapping] = self::unwrap($arg);
            if ($named instanceof ArgDef) {
                return $named->with(['type' => self::applyWrapping($named->config['type'], $wrapping)]);
            }

            return new ArgDef(['type' => self::applyWrapping($named, $wrapping)]);
        }

        return new ArgDef(['type' => $arg]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function runHook(string $hook, array $config): array
    {
        foreach ($this->plugins as $plugin) {
            $fn = $plugin->hook($hook);
            if ($fn === null) {
                continue;
            }
            $result = $fn($config);
            if (is_array($result)) {
                $config = $result;
            }
        }

        return $config;
    }

    /** Registers the named type definitions a field / argument type references (nexus does). */
    private function discover(mixed $typeRef): void
    {
        [$named] = self::unwrap($typeRef);
        if ($named instanceof ArgDef) {
            $this->discover($named->config['type']);

            return;
        }
        if ($named instanceof NamedTypeDef || ($named instanceof Type && $named instanceof NamedType)) {
            $this->addType($named);
        }
    }

    /**
     * nexus' `unwrapNexusDef`: the named type and its wrapping, innermost first.
     *
     * @return array{0: mixed, 1: list<string>}
     */
    private static function unwrap(mixed $typeRef): array
    {
        $wrapping = [];
        $named = $typeRef;
        while (true) {
            if ($named instanceof WrappedTypeDef) {
                array_unshift($wrapping, $named->kind);
                $named = $named->ofType;
            } elseif ($named instanceof NonNull) {
                array_unshift($wrapping, WrappedTypeDef::NON_NULL);
                $named = $named->getWrappedType();
            } elseif ($named instanceof ListOfType) {
                array_unshift($wrapping, WrappedTypeDef::LIST);
                $named = $named->getWrappedType();
            } else {
                break;
            }
        }

        return [$named, $wrapping];
    }

    /** @param list<string> $wrapping innermost first */
    private static function applyWrapping(mixed $type, array $wrapping): mixed
    {
        foreach ($wrapping as $kind) {
            $type = new WrappedTypeDef($kind, $type);
        }

        return $type;
    }

    /**
     * nexus' `finalizeWrapping` with `nonNullDefault = false`.
     *
     * @param list<string> $typeWrapping
     * @param list<string> $chainWrapping
     * @return list<string>
     */
    private static function finalizeWrapping(array $typeWrapping, array $chainWrapping = []): array
    {
        $final = [];
        foreach ([...$typeWrapping, ...$chainWrapping] as $kind) {
            if ($kind !== WrappedTypeDef::NULL) {
                $final[] = $kind;
            }
        }

        return $final;
    }

    // ------------------------------------------------------------------------------------------
    // graphql types

    private function buildSchema(): Schema
    {
        foreach (array_keys($this->defs) as $name) {
            $this->getNamedType($name);
        }

        $query = isset($this->defs['Query']) ? $this->getNamedType('Query') : null;
        $mutation = isset($this->defs['Mutation']) ? $this->getNamedType('Mutation') : null;
        $subscription = isset($this->defs['Subscription']) ? $this->getNamedType('Subscription') : null;

        return new Schema([
            'query' => $query instanceof ObjectType ? $query : null,
            'mutation' => $mutation instanceof ObjectType ? $mutation : null,
            'subscription' => $subscription instanceof ObjectType ? $subscription : null,
            'types' => array_values($this->built),
        ]);
    }

    /** @return Type&NamedType */
    private function getNamedType(string $name): Type
    {
        $standard = match ($name) {
            'String' => Type::string(),
            'Int' => Type::int(),
            'Float' => Type::float(),
            'Boolean' => Type::boolean(),
            'ID' => Type::id(),
            default => null,
        };
        if ($standard !== null) {
            return $standard;
        }

        if (isset($this->built[$name])) {
            return $this->built[$name];
        }

        $def = $this->defs[$name] ?? null;
        if ($def === null) {
            throw new \RuntimeException("Missing type {$name}, did you forget to import a type to the root query?");
        }

        $type = $this->buildNamedType($name, $def);
        $this->built[$name] = $type;

        return $type;
    }

    /** @return Type&NamedType */
    private function buildNamedType(string $name, NamedTypeDef|Type $def): Type
    {
        $resolvers = $this->resolvers[$name] ?? null;

        if ($def instanceof Type) {
            if (!$def instanceof NamedType) {
                throw new \RuntimeException("Expected a named type for {$name}");
            }

            return $def;
        }

        if ($def instanceof ObjectTypeDef) {
            $isTypeOf = is_array($resolvers) && is_callable($resolvers['__isTypeOf'] ?? null) ? $resolvers['__isTypeOf'] : ($def->config['isTypeOf'] ?? null);

            return new ObjectType([
                'name' => $name,
                'description' => $def->description(),
                'fields' => fn (): array => $this->buildOutputFields($name),
                'interfaces' => fn (): array => $this->buildInterfaces($name),
                'isTypeOf' => is_callable($isTypeOf)
                    ? static fn (mixed $value, mixed $context, ResolveInfo $info): bool => (bool) $isTypeOf($value, $context, $info)
                    : null,
            ]);
        }

        if ($def instanceof InterfaceTypeDef) {
            $resolveType = is_array($resolvers) && is_callable($resolvers['__resolveType'] ?? null) ? $resolvers['__resolveType'] : ($def->config['resolveType'] ?? null);

            return new InterfaceType([
                'name' => $name,
                'description' => $def->description(),
                'fields' => fn (): array => $this->buildOutputFields($name),
                'resolveType' => is_callable($resolveType) ? $resolveType : null,
            ]);
        }

        if ($def instanceof InputObjectTypeDef) {
            return new InputObjectType([
                'name' => $name,
                'description' => $def->description(),
                'fields' => fn (): array => $this->buildInputFields($name),
            ]);
        }

        if ($def instanceof UnionTypeDef) {
            $resolveType = is_array($resolvers) && is_callable($resolvers['__resolveType'] ?? null) ? $resolvers['__resolveType'] : ($def->config['resolveType'] ?? null);

            return new UnionType([
                'name' => $name,
                'description' => $def->description(),
                'types' => function () use ($name): array {
                    $types = [];
                    foreach (array_unique($this->unionMembers[$name] ?? []) as $member) {
                        $type = $this->getNamedType($member);
                        if ($type instanceof ObjectType) {
                            $types[] = $type;
                        }
                    }

                    return $types;
                },
                'resolveType' => is_callable($resolveType) ? $resolveType : null,
            ]);
        }

        if ($def instanceof EnumTypeDef) {
            return new EnumType([
                'name' => $name,
                'description' => $def->description(),
                'values' => self::enumValues($def->config['members'] ?? [], is_array($resolvers) ? $resolvers : []),
            ]);
        }

        if ($def instanceof ScalarTypeDef) {
            if ($resolvers instanceof ScalarType) {
                return self::copyScalar($name, $resolvers, $def->description());
            }

            if ($def->scalar !== null) {
                return $def->scalar;
            }

            $identity = static fn (mixed $value): mixed => $value;
            $serialize = $def->config['serialize'] ?? $identity;
            $parseValue = $def->config['parseValue'] ?? $identity;
            $parseLiteral = $def->config['parseLiteral'] ?? static fn (Node $ast, ?array $variables = null): mixed => AST::valueFromASTUntyped($ast, $variables);

            $serialize = is_callable($serialize) ? $serialize : $identity;
            $parseValue = is_callable($parseValue) ? $parseValue : $identity;
            $parseLiteral = is_callable($parseLiteral) ? $parseLiteral : static fn (Node $ast, ?array $variables = null): mixed => AST::valueFromASTUntyped($ast, $variables);

            return new CustomScalarType([
                'name' => $name,
                'description' => $def->description(),
                'serialize' => static fn (mixed $value): mixed => $serialize($value),
                'parseValue' => static fn (mixed $value): mixed => $parseValue($value),
                'parseLiteral' => static fn (Node $ast, ?array $variables = null): mixed => $parseLiteral($ast, $variables),
            ]);
        }

        throw new \RuntimeException("Cannot build the type {$name}");
    }

    /** @return list<InterfaceType> */
    private function buildInterfaces(string $name): array
    {
        $interfaces = [];
        foreach ($this->interfaces[$name] ?? [] as $interfaceName) {
            $interface = $this->getNamedType($interfaceName);
            if ($interface instanceof InterfaceType) {
                $interfaces[] = $interface;
            }
        }

        return $interfaces;
    }

    private static function copyScalar(string $name, ScalarType $scalar, ?string $description): CustomScalarType
    {
        return new CustomScalarType([
            'name' => $name,
            'description' => $scalar->description ?? $description,
            'serialize' => [$scalar, 'serialize'],
            'parseValue' => [$scalar, 'parseValue'],
            'parseLiteral' => [$scalar, 'parseLiteral'],
        ]);
    }

    /**
     * @param array<string, mixed> $resolvers `{ NAME: internalValue }` overrides (graphql-tools)
     * @return array<string, array<string, mixed>>
     */
    private static function enumValues(mixed $members, array $resolvers): array
    {
        $values = [];
        if (is_array($members) && array_is_list($members)) {
            foreach ($members as $member) {
                if (is_string($member)) {
                    $values[$member] = ['value' => $member];
                } elseif (is_array($member) && is_string($member['name'] ?? null)) {
                    $values[$member['name']] = [
                        'value' => array_key_exists('value', $member) ? $member['value'] : $member['name'],
                        'description' => $member['description'] ?? null,
                        'deprecationReason' => $member['deprecation'] ?? null,
                    ];
                }
            }
        } elseif (is_array($members)) {
            foreach ($members as $memberName => $value) {
                $values[(string) $memberName] = ['value' => $value];
            }
        }

        foreach ($resolvers as $memberName => $value) {
            if (isset($values[$memberName])) {
                $values[$memberName]['value'] = $value;
            }
        }

        return $values;
    }

    /** @return array<string, array<string, mixed>> */
    private function buildOutputFields(string $typeName): array
    {
        $fields = [];
        foreach ($this->outputFields[$typeName] ?? [] as $fieldName => $field) {
            $type = $this->resolveTypeRef($field['type'] ?? null, self::wrappingOf($field));
            if (!$type instanceof OutputType) {
                throw new \RuntimeException("{$typeName}.{$fieldName} must be an output type");
            }

            /** @var array<string, ArgDef> $argDefs */
            $argDefs = $field['args'] ?? [];
            $config = [
                'name' => $fieldName,
                'type' => $type,
                'args' => $this->buildArgs($argDefs),
                'description' => is_string($field['description'] ?? null) ? $field['description'] : null,
                'deprecationReason' => is_string($field['deprecation'] ?? null) ? $field['deprecation'] : null,
                'extensions' => is_array($field['extensions'] ?? null) ? $field['extensions'] : [],
            ];
            if (is_callable($field['resolve'] ?? null)) {
                $config['resolve'] = $field['resolve'];
            }
            $fields[$fieldName] = $config;
        }

        return $fields;
    }

    /**
     * @param array<string, ArgDef> $argDefs
     * @return array<string, array<string, mixed>>
     */
    private function buildArgs(array $argDefs): array
    {
        $args = [];
        foreach ($argDefs as $argName => $argDef) {
            $type = $this->resolveTypeRef($argDef->config['type']);
            if (!$type instanceof InputType) {
                throw new \RuntimeException("Argument {$argName} must be an input type");
            }
            $arg = [
                'type' => $type,
                'description' => is_string($argDef->config['description'] ?? null) ? $argDef->config['description'] : null,
            ];
            if (array_key_exists('default', $argDef->config)) {
                $arg['defaultValue'] = $argDef->config['default'];
            } elseif (($argDef->config['defaultAst'] ?? null) instanceof ValueNode && $argDef->config['defaultAst'] instanceof Node) {
                $arg['defaultValue'] = AST::valueFromAST($argDef->config['defaultAst'], $type);
            }
            $args[$argName] = $arg;
        }

        return $args;
    }

    /** @return array<string, array{type: InputType&Type, description: string|null, defaultValue?: mixed}> */
    private function buildInputFields(string $typeName): array
    {
        $fields = [];
        foreach ($this->inputFields[$typeName] ?? [] as $fieldName => $field) {
            $type = $this->resolveTypeRef($field['type'] ?? null, self::wrappingOf($field));
            if (!$type instanceof InputType) {
                throw new \RuntimeException("{$typeName}.{$fieldName} must be an input type");
            }
            $config = [
                'type' => $type,
                'description' => is_string($field['description'] ?? null) ? $field['description'] : null,
            ];
            if (array_key_exists('default', $field)) {
                $config['defaultValue'] = $field['default'];
            } elseif (($field['defaultAst'] ?? null) instanceof ValueNode && $field['defaultAst'] instanceof Node) {
                $config['defaultValue'] = AST::valueFromAST($field['defaultAst'], $type);
            }
            $fields[$fieldName] = $config;
        }

        return $fields;
    }

    /**
     * @param FieldRecord $field
     * @return list<string>
     */
    private static function wrappingOf(array $field): array
    {
        $wrapping = $field['wrapping'] ?? [];

        return is_array($wrapping) ? array_values(array_filter($wrapping, 'is_string')) : [];
    }

    /** @param list<string> $chainWrapping */
    private function resolveTypeRef(mixed $typeRef, array $chainWrapping = []): Type
    {
        [$named, $wrapping] = self::unwrap($typeRef);

        if ($named instanceof ArgDef) {
            return $this->resolveTypeRef(self::applyWrapping($named->config['type'], $wrapping), $chainWrapping);
        }

        if (is_string($named)) {
            $type = $this->getNamedType($named);
        } elseif ($named instanceof NamedTypeDef) {
            $type = $this->getNamedType($named->name);
        } elseif ($named instanceof Type && $named instanceof NamedType) {
            $type = $this->getNamedType($named->name());
        } else {
            throw new \RuntimeException('Invalid type reference ' . get_debug_type($named));
        }

        foreach (self::finalizeWrapping($wrapping, $chainWrapping) as $kind) {
            if ($kind === WrappedTypeDef::LIST) {
                $type = Type::listOf($type);
            } elseif ($type instanceof NullableType) {
                $type = Type::nonNull($type);
            }
        }

        return $type;
    }
}
