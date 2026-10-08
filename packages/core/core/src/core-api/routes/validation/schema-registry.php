<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Routes\Validation;

use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodRegistry;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of packages/core/core/src/core-api/routes/validation/schema-registry.ts
 * (`createContentAPISchemaRegistry`): a per-application store of named Zod schemas for
 * content-API validation (`Core.ContentAPISchemaRegistry`).
 *
 * An owned Zod registry is kept in sync with the ID map so schema identity is preserved during
 * construction. The map is the source of truth for lookup and iteration; OpenAPI conversion must
 * copy `entries()` into a conversion-local registry rather than reading the live Zod registry.
 */
final class SchemaRegistry
{
    private ZodRegistry $registry;

    /** @var array<string, ZodType> */
    private array $schemas = [];

    /** @var array<string, true> */
    private array $pending = [];

    private function __construct()
    {
        $this->registry = z::registry();
    }

    public static function createContentAPISchemaRegistry(): self
    {
        return new self();
    }

    public function get(string $id): ?ZodType
    {
        return $this->schemas[$id] ?? null;
    }

    public function getRequired(string $id): ZodType
    {
        return $this->get($id) ?? throw new \RuntimeException("Content-API schema \"{$id}\" was not registered");
    }

    public function isPending(string $id): bool
    {
        return isset($this->pending[$id]);
    }

    public function set(string $id, ZodType $schema): void
    {
        $existing = $this->schemas[$id] ?? null;

        if ($existing !== null) {
            $this->registry->remove($existing);
            unset($this->schemas[$id]);
        }

        $this->registry->add($schema, ['id' => $id]);
        $this->schemas[$id] = $schema;
    }

    public function has(string $id): bool
    {
        return isset($this->schemas[$id]);
    }

    /**
     * The registered schema, or a lazy stand-in when the construction of `$id` is still in
     * progress (cycle breaking).
     */
    public function getOrDefer(string $id): ?ZodType
    {
        if ($this->isPending($id)) {
            return z::lazy(fn (): ZodType => $this->getRequired($id));
        }

        return $this->get($id);
    }

    public function startPending(string $id): void
    {
        $this->pending[$id] = true;
    }

    public function finishPending(string $id): void
    {
        unset($this->pending[$id]);
    }

    /** @return array<string, ZodType> id => schema, in registration order */
    public function entries(): array
    {
        return $this->schemas;
    }

    public function remove(string $id): bool
    {
        $schema = $this->schemas[$id] ?? null;

        if ($schema === null) {
            return false;
        }

        $this->registry->remove($schema);
        unset($this->schemas[$id]);

        return true;
    }

    public function clear(): void
    {
        $this->registry->clear();
        $this->schemas = [];
        $this->pending = [];
    }
}
