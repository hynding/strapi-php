<?php

declare(strict_types=1);

namespace Strapi\Core\Registries;

use Strapi\Core\Domain\ContentType\ContentType;
use Strapi\Types\Schema\Schema;

/**
 * Port of packages/core/core/src/registries/content-types.ts.
 *
 * `add(namespace, definitions)` takes the loader output: `['article' => ['schema' => [...], 'actions' => [], 'lifecycles' => [...]]]`.
 */
final class ContentTypes
{
    /** @var array<string, Schema> */
    private array $contentTypes = [];

    /** @param array<string, array{schema: array<string, mixed>, actions?: array<string, mixed>, lifecycles?: array<string, mixed>}> $contentTypes */
    private static function validateKeySameToSingularName(array $contentTypes): void
    {
        foreach ($contentTypes as $ctName => $contentType) {
            $singularName = $contentType['schema']['info']['singularName'] ?? null;
            if ((string) $ctName !== $singularName) {
                throw new \RuntimeException("The key of the content-type should be the same as its singularName. Found {$ctName} and {$singularName}.");
            }
        }
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->contentTypes);
    }

    public function get(string $uid): ?Schema
    {
        return $this->contentTypes[$uid] ?? null;
    }

    /** @return array<string, Schema> */
    public function getAll(string $namespace = ''): array
    {
        return array_filter($this->contentTypes, static fn (string $uid): bool => Namespace_::hasNamespace($uid, $namespace), ARRAY_FILTER_USE_KEY);
    }

    public function set(string $uid, Schema $contentType): static
    {
        $this->contentTypes[$uid] = $contentType;

        return $this;
    }

    /** @param array<string, array{schema: array<string, mixed>, actions?: array<string, mixed>, lifecycles?: array<string, mixed>}> $newContentTypes */
    public function add(string $namespace, array $newContentTypes): void
    {
        self::validateKeySameToSingularName($newContentTypes);

        foreach ($newContentTypes as $rawCtName => $definition) {
            $uid = Namespace_::addNamespace((string) $rawCtName, $namespace);

            if (array_key_exists($uid, $this->contentTypes)) {
                throw new \RuntimeException("Content-type {$uid} has already been registered.");
            }

            $this->contentTypes[$uid] = ContentType::createContentType($uid, $definition);
        }
    }

    /**
     * Wraps a contentType to extend it. Schemas are immutable value objects here, so the callback
     * receives the current schema and returns the new one (upstream mutates in place).
     *
     * @param callable(Schema): (Schema|null) $extendFn
     */
    public function extend(string $ctUID, callable $extendFn): static
    {
        $currentContentType = $this->get($ctUID);

        if ($currentContentType === null) {
            throw new \RuntimeException("Content-Type {$ctUID} doesn't exist");
        }

        $result = $extendFn($currentContentType);
        if ($result instanceof Schema) {
            $this->contentTypes[$ctUID] = $result;
        }

        return $this;
    }
}
