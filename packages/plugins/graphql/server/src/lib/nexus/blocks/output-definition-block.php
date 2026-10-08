<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib\Nexus\Blocks;

use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\NamedTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\WrappedTypeDef;

/**
 * nexus' `OutputDefinitionBlock` (the `t` of an `objectType` / `extendType` / `interfaceType`
 * definition): `t.field(name, config)`, the scalar shorthands (`t.string()`, `t.id()`...), the
 * methods added by `asNexusMethod` scalars (`t.json()`, `t.dateTime()`...) and the chainable
 * `t.nonNull` / `t.nullable` / `t.list` modifiers.
 *
 * @property-read self $nonNull
 * @property-read self $nullable
 * @property-read self $list
 *
 * @method void json(string $name, array<string, mixed> $config = [])
 * @method void dateTime(string $name, array<string, mixed> $config = [])
 * @method void date(string $name, array<string, mixed> $config = [])
 * @method void time(string $name, array<string, mixed> $config = [])
 * @method void long(string $name, array<string, mixed> $config = [])
 */
class OutputDefinitionBlock
{
    /**
     * @param \Closure(array<string, mixed>): void $addField
     * @param array<string, string> $dynamicMethods method name => scalar type name
     * @param list<string> $wrapping chained wrapping, innermost first (nexus order)
     * @param (\Closure(string): void)|null $addInterface
     */
    final public function __construct(
        protected readonly string $typeName,
        protected readonly \Closure $addField,
        protected readonly array $dynamicMethods = [],
        protected readonly array $wrapping = [],
        protected readonly ?\Closure $addInterface = null,
    ) {
    }

    /** `t.implements(...interfaces)` (object types) */
    public function implements(string|NamedTypeDef ...$interfaces): void
    {
        if ($this->addInterface === null) {
            throw new \BadMethodCallException("{$this->typeName} cannot implement interfaces");
        }
        foreach ($interfaces as $interface) {
            ($this->addInterface)($interface instanceof NamedTypeDef ? $interface->name : $interface);
        }
    }

    public function typeName(): string
    {
        return $this->typeName;
    }

    public function __get(string $name): static
    {
        return match ($name) {
            'nonNull' => $this->wrapClass(WrappedTypeDef::NON_NULL),
            'nullable' => $this->wrapClass(WrappedTypeDef::NULL),
            'list' => $this->wrapClass(WrappedTypeDef::LIST),
            default => throw new \LogicException("Unknown definition block modifier \"{$name}\""),
        };
    }

    protected function wrapClass(string $kind): static
    {
        $previous = $this->wrapping[0] ?? null;
        $isNullability = $kind === WrappedTypeDef::NON_NULL || $kind === WrappedTypeDef::NULL;
        $wrapping = $isNullability && ($previous === WrappedTypeDef::NON_NULL || $previous === WrappedTypeDef::NULL)
            ? $this->wrapping
            : [$kind, ...$this->wrapping];

        return new static($this->typeName, $this->addField, $this->dynamicMethods, $wrapping, $this->addInterface);
    }

    /** @param array<string, mixed> $config */
    public function field(string $name, array $config = []): void
    {
        if (!array_key_exists('type', $config)) {
            throw new \InvalidArgumentException("Missing required \"type\" field for {$this->typeName}.{$name}");
        }

        ($this->addField)([...$config, 'name' => $name, 'wrapping' => $this->wrapping, 'parentType' => $this->typeName]);
    }

    /** @param array<string, mixed> $config */
    public function string(string $name, array $config = []): void
    {
        $this->field($name, [...$config, 'type' => 'String']);
    }

    /** @param array<string, mixed> $config */
    public function int(string $name, array $config = []): void
    {
        $this->field($name, [...$config, 'type' => 'Int']);
    }

    /** @param array<string, mixed> $config */
    public function float(string $name, array $config = []): void
    {
        $this->field($name, [...$config, 'type' => 'Float']);
    }

    /** @param array<string, mixed> $config */
    public function boolean(string $name, array $config = []): void
    {
        $this->field($name, [...$config, 'type' => 'Boolean']);
    }

    /** @param array<string, mixed> $config */
    public function id(string $name, array $config = []): void
    {
        $this->field($name, [...$config, 'type' => 'ID']);
    }

    /** @param array<int, mixed> $args */
    public function __call(string $method, array $args): void
    {
        $type = $this->dynamicMethods[$method] ?? null;
        if ($type === null) {
            throw new \BadMethodCallException("Unknown definition block method \"{$method}\" on {$this->typeName}");
        }

        $name = $args[0] ?? null;
        $config = $args[1] ?? [];
        if (!is_string($name) || !is_array($config)) {
            throw new \InvalidArgumentException("t.{$method}(name, config) expects a field name and a config array");
        }

        $this->field($name, [...$config, 'type' => $type]);
    }
}
