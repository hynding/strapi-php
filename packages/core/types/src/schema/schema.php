<?php

declare(strict_types=1);

namespace Strapi\Types\Schema;

use Strapi\Types\Schema\Attribute\Type;

/**
 * A content-type or component schema, as loaded from schema.json plus the computed fields
 * Strapi adds at load time (uid, modelName, globalId, collectionName...).
 * Mirrors packages/core/types/src/struct/schema.ts.
 *
 * @phpstan-type AttributeArray array<string, mixed>
 */
class Schema
{
    /**
     * @param 'collectionType'|'singleType'|null $kind  null for components
     * @param array<string, AttributeArray> $attributes
     * @param array<string, mixed> $info
     * @param array<string, mixed> $options
     * @param array<string, mixed> $pluginOptions
     * @param array<string, mixed> $config  config.js/config.php for API content types (lifecycles...)
     */
    public function __construct(
        public readonly string $uid,
        public readonly string $modelType, // 'contentType' | 'component'
        public readonly ?string $kind,
        public readonly string $modelName,
        public readonly string $globalId,
        public readonly string $collectionName,
        public readonly ?string $plugin,
        public readonly ?string $apiName,
        public readonly ?string $category, // components only
        public readonly array $info,
        public readonly array $options,
        public readonly array $pluginOptions,
        public readonly array $attributes,
        public readonly array $config = [],
    ) {
    }

    public function isCollectionType(): bool
    {
        return $this->kind === 'collectionType';
    }

    public function isSingleType(): bool
    {
        return $this->kind === 'singleType';
    }

    public function isComponent(): bool
    {
        return $this->modelType === 'component';
    }

    public function hasDraftAndPublish(): bool
    {
        return ($this->options['draftAndPublish'] ?? false) === true;
    }

    public function hasAttribute(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    /** @return AttributeArray|null */
    public function attribute(string $name): ?array
    {
        return $this->attributes[$name] ?? null;
    }

    public function attributeType(string $name): ?Type
    {
        $type = $this->attributes[$name]['type'] ?? null;

        return is_string($type) ? Type::tryFrom($type) : null;
    }

    /** @return array<string, AttributeArray> */
    public function attributesOfType(Type ...$types): array
    {
        $wanted = array_map(static fn (Type $t) => $t->value, $types);

        return array_filter($this->attributes, static fn (array $a) => in_array($a['type'] ?? null, $wanted, true));
    }

    /** @return array<string, mixed> plain schema.json shape, used by the admin API and data-transfer */
    public function toArray(): array
    {
        $out = [
            'uid' => $this->uid,
            'modelType' => $this->modelType,
            'modelName' => $this->modelName,
            'globalId' => $this->globalId,
            'collectionName' => $this->collectionName,
            'info' => $this->info,
            'options' => $this->options,
            'pluginOptions' => $this->pluginOptions,
            'attributes' => $this->attributes,
        ];
        if ($this->kind !== null) {
            $out['kind'] = $this->kind;
        }
        if ($this->plugin !== null) {
            $out['plugin'] = $this->plugin;
        }
        if ($this->apiName !== null) {
            $out['apiName'] = $this->apiName;
        }
        if ($this->category !== null) {
            $out['category'] = $this->category;
        }

        return $out;
    }
}
