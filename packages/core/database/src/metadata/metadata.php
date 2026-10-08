<?php

declare(strict_types=1);

namespace Strapi\Database\Metadata;

use Strapi\Database\Utils\Identifiers\Identifiers;
use Strapi\Database\Utils\Types;

/**
 * Port of packages/core/database/src/metadata/metadata.ts.
 *
 * A map of uid => Meta, where Meta is the database-level model description
 * (`{ uid, singularName, tableName, attributes, indexes, foreignKeys, lifecycles, columnToAttribute }`).
 * Models are plain arrays in the shape upstream's `Model` has; see Strapi\Database\Utils\SchemaFactory
 * for converting `Strapi\Types\Schema\Schema` objects to models.
 *
 * @phpstan-type Attribute array<string, mixed>
 * @phpstan-type Index array{name: string, columns: list<string>, type?: string|null}
 * @phpstan-type ForeignKey array{name: string, columns: list<string>, referencedColumns: list<string>, referencedTable: string, onUpdate?: string|null, onDelete?: string|null}
 * @phpstan-type Model array{uid: string, singularName: string, tableName: string, attributes: array<string, Attribute>, indexes?: list<Index>, foreignKeys?: list<ForeignKey>, lifecycles?: array<string, callable>}
 * @phpstan-type Meta array{uid: string, singularName: string, tableName: string, attributes: array<string, Attribute>, indexes: list<Index>, foreignKeys: list<ForeignKey>, lifecycles: array<string, callable>, columnToAttribute: array<string, string>}
 *
 * @implements \IteratorAggregate<string, Meta>
 */
class Metadata implements \IteratorAggregate, \Countable
{
    /** @var array<string, Meta> */
    private array $metas = [];

    final public function __construct()
    {
    }

    /** @param list<Model> $models */
    public static function create(array $models = []): static
    {
        $metadata = new static();

        if ($models !== []) {
            $metadata->loadModels($models);
        }

        return $metadata;
    }

    public function identifiers(): Identifiers
    {
        return Identifiers::global();
    }

    /** @return Meta */
    public function get(string $uid): array
    {
        if (!isset($this->metas[$uid])) {
            throw new \InvalidArgumentException("Metadata for \"{$uid}\" not found");
        }

        return $this->metas[$uid];
    }

    public function has(string $uid): bool
    {
        return isset($this->metas[$uid]);
    }

    /** @param Meta|Model $meta */
    public function add(array $meta): static
    {
        return $this->set($meta['uid'], $meta);
    }

    /** @param Meta|Model $meta */
    public function set(string $uid, array $meta): static
    {
        $this->metas[$uid] = $meta + ['lifecycles' => [], 'indexes' => [], 'foreignKeys' => [], 'columnToAttribute' => []];

        return $this;
    }

    public function delete(string $uid): void
    {
        unset($this->metas[$uid]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->metas);
    }

    /** @return list<Meta> */
    public function values(): array
    {
        return array_values($this->metas);
    }

    /** @return \ArrayIterator<string, Meta> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->metas);
    }

    public function count(): int
    {
        return count($this->metas);
    }

    /**
     * Rewrites one attribute of a model in place (relations.ts does `Object.assign(inverseAttribute, ...)`).
     *
     * @param callable(Attribute): Attribute $update
     */
    public function updateAttribute(string $uid, string $attributeName, callable $update): void
    {
        if (!isset($this->metas[$uid]['attributes'][$attributeName])) {
            throw new \InvalidArgumentException("Attribute {$attributeName} not found on {$uid}");
        }

        $this->metas[$uid]['attributes'][$attributeName] = $update($this->metas[$uid]['attributes'][$attributeName]);
    }

    /**
     * Validate the DB metadata, throwing an error if a duplicate DB table name is detected.
     */
    public function validate(): void
    {
        $seenTables = [];
        foreach ($this->metas as $meta) {
            if (isset($seenTables[$meta['tableName']])) {
                throw new \RuntimeException("DB table \"{$meta['tableName']}\" already exists. Change the collectionName of the related content type.");
            }
            $seenTables[$meta['tableName']] = true;
        }
    }

    /** @param list<Model> $models */
    public function loadModels(array $models): void
    {
        $identifiers = Identifiers::global();

        // init pass
        foreach ($models as $model) {
            $tableName = $identifiers->getTableName($model['tableName']);
            $this->add([
                ...$model,
                'tableName' => $tableName,
                'attributes' => $model['attributes'] ?? [],
                'lifecycles' => $model['lifecycles'] ?? [],
                'indexes' => $model['indexes'] ?? [],
                'foreignKeys' => $model['foreignKeys'] ?? [],
                'columnToAttribute' => [],
            ]);
        }

        // build compos / relations — join tables added while iterating are visited too (as with a JS Map)
        $processed = [];
        while (count($processed) < count($this->metas)) {
            foreach (array_keys($this->metas) as $uid) {
                if (isset($processed[$uid])) {
                    continue;
                }
                $processed[$uid] = true;

                $meta = &$this->metas[$uid];
                foreach (array_keys($meta['attributes']) as $attributeName) {
                    $attribute = &$meta['attributes'][$attributeName];
                    try {
                        if (!empty($attribute['unstable_virtual'])) {
                            continue;
                        }

                        if (Types::isRelationalAttribute($attribute)) {
                            Relations::createRelation((string) $attributeName, $attribute, $meta, $this);
                            continue;
                        }

                        self::createAttribute((string) $attributeName, $attribute);
                    } catch (\Throwable $error) {
                        throw new \RuntimeException(
                            "Error on attribute {$attributeName} in model {$meta['singularName']}({$meta['uid']}): {$error->getMessage()}",
                            0,
                            $error,
                        );
                    } finally {
                        unset($attribute);
                    }
                }
                unset($meta);
            }
        }

        foreach ($this->metas as $uid => $meta) {
            $columnToAttribute = [];
            foreach ($meta['attributes'] as $key => $attribute) {
                if (array_key_exists('columnName', $attribute)) {
                    $columnToAttribute[$attribute['columnName'] ?: $key] = (string) $key;
                } else {
                    $columnToAttribute[$key] = (string) $key;
                }
            }
            $this->metas[$uid]['columnToAttribute'] = $columnToAttribute;
        }

        $this->validate();
    }

    /** @param Attribute $attribute */
    private static function createAttribute(string $attributeName, array &$attribute): void
    {
        // if the attribute has already set its own column name, use that
        // this will prevent us from shortening a name twice
        if (!empty($attribute['columnName'])) {
            return;
        }

        $attribute['columnName'] = AttributeNaming::columnName($attributeName);
    }
}
