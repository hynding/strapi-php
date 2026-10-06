<?php

declare(strict_types=1);

namespace Strapi\Utils;

use Strapi\Types\Schema\Schema;
use Strapi\Utils\Traverse\Path;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;

/**
 * Port of packages/core/utils/src/traverse-entity.ts: visit every key of an entity following
 * relations, media, components and dynamic zones through the schema.
 *
 * Visitor signature: `function (VisitorOptions $options, VisitorUtils $utils): void`.
 * `$options` carries key, value, attribute, schema, path, data, getModel, parent, allowedExtraRootKeys;
 * `$utils->set($key, $value)` / `$utils->remove($key)` mutate the (cloned) node being visited.
 *
 * @phpstan-type Model Schema|array<string, mixed>
 * @phpstan-type GetModel callable(string): (Schema|array<string, mixed>|null)
 * @phpstan-type Options array{schema: Schema|array<string, mixed>|null, getModel: callable(string): (Schema|array<string, mixed>|null), path?: Path|null, parent?: \Strapi\Utils\Traverse\ParentNode|null, allowedExtraRootKeys?: list<string>|null}
 */
final class TraverseEntity
{
    /**
     * Curried form: `TraverseEntity::create($visitor, $options)` returns `callable(mixed $entity): mixed`.
     *
     * @param callable(VisitorOptions, VisitorUtils): void $visitor
     * @param Options $options
     * @return \Closure(mixed): mixed
     */
    public static function create(callable $visitor, array $options): \Closure
    {
        return static fn (mixed $entity): mixed => self::traverse($visitor, $options, $entity);
    }

    /**
     * @param callable(VisitorOptions, VisitorUtils): void $visitor
     * @param Options $options
     */
    public static function traverse(callable $visitor, array $options, mixed $entity): mixed
    {
        $path = $options['path'] ?? new Path(null, null, null);
        $schema = $options['schema'] ?? null;
        $getModel = $options['getModel'];
        $allowedExtraRootKeys = $options['allowedExtraRootKeys'] ?? null;
        $parent = $options['parent'] ?? null;

        // End recursion
        if (!is_array($entity) || $schema === null) {
            return $entity;
        }

        $copy = $entity; // PHP arrays are copied on write: the original stays untouched
        $utils = new VisitorUtils($copy);

        // `$parent` is reassigned below before each nested traversal (as upstream mutates `parent`), hence the reference
        $recurse = static function (Schema|array|null $targetSchema, Path $entryPath, mixed $entry) use ($visitor, $getModel, &$parent, $allowedExtraRootKeys): mixed {
            return self::traverse($visitor, [
                'schema' => $targetSchema,
                'path' => $entryPath,
                'getModel' => $getModel,
                'parent' => $parent,
                'allowedExtraRootKeys' => $allowedExtraRootKeys,
            ], $entry);
        };

        foreach (array_keys($copy) as $key) {
            $key = (string) $key;
            $attribute = ContentTypes::attribute($schema, $key);

            $newPath = new Path(
                $path->raw === null ? $key : "{$path->raw}.{$key}",
                $attribute !== null ? ($path->attribute === null ? $key : "{$path->attribute}.{$key}") : $path->attribute,
                $path->rawWithIndices === null ? $key : "{$path->rawWithIndices}.{$key}",
            );

            $visitor(new VisitorOptions(
                data: $copy,
                schema: $schema,
                key: $key,
                value: $copy[$key] ?? null,
                attribute: $attribute,
                path: $newPath,
                getModel: $getModel,
                parent: $parent,
                allowedExtraRootKeys: $allowedExtraRootKeys,
            ), $utils);

            $copy = $utils->data();

            // Extract the value for the current key (after calling the visitor)
            $value = $copy[$key] ?? null;

            if ($value === null || $attribute === null) {
                continue;
            }

            $withIndex = static fn (int $i): Path => new Path(
                $newPath->raw,
                $newPath->attribute,
                $newPath->rawWithIndices === null ? (string) $i : "{$newPath->rawWithIndices}.{$i}",
            );

            if (ContentTypes::isRelationalAttribute($attribute)) {
                $parent = new Traverse\ParentNode($key, $newPath, $schema, $attribute);
                $isMorphRelation = str_starts_with(strtolower((string) ($attribute['relation'] ?? '')), 'morph');

                $method = $isMorphRelation
                    ? static function (Path $p, mixed $entry) use ($recurse, $getModel): mixed {
                        $type = is_array($entry) ? ($entry['__type'] ?? null) : null;

                        return $recurse(is_string($type) ? $getModel($type) : null, $p, $entry);
                    }
                : (static function (Path $p, mixed $entry) use ($recurse, $getModel, $attribute): mixed {
                    return $recurse($getModel((string) ($attribute['target'] ?? '')), $p, $entry);
                });

                if (is_array($value) && array_is_list($value)) {
                    $out = [];
                    foreach ($value as $i => $item) {
                        $out[] = $method($withIndex($i), $item);
                    }
                    $copy[$key] = $out;
                } else {
                    $copy[$key] = $method($newPath, $value);
                }
                $utils->replace($copy);

                continue;
            }

            if (ContentTypes::isMediaAttribute($attribute)) {
                $parent = new Traverse\ParentNode($key, $newPath, $schema, $attribute);
                $targetSchema = $getModel('plugin::upload.file');

                if (is_array($value) && array_is_list($value)) {
                    $out = [];
                    foreach ($value as $i => $item) {
                        $out[] = $recurse($targetSchema, $withIndex($i), $item);
                    }
                    $copy[$key] = $out;
                } else {
                    $copy[$key] = $recurse($targetSchema, $newPath, $value);
                }
                $utils->replace($copy);

                continue;
            }

            if (($attribute['type'] ?? null) === 'component') {
                $parent = new Traverse\ParentNode($key, $newPath, $schema, $attribute);
                $targetSchema = $getModel((string) ($attribute['component'] ?? ''));

                if (is_array($value) && array_is_list($value)) {
                    $out = [];
                    foreach ($value as $i => $item) {
                        $out[] = $recurse($targetSchema, $withIndex($i), $item);
                    }
                    $copy[$key] = $out;
                } else {
                    $copy[$key] = $recurse($targetSchema, $newPath, $value);
                }
                $utils->replace($copy);

                continue;
            }

            if (($attribute['type'] ?? null) === 'dynamiczone' && is_array($value) && array_is_list($value)) {
                $parent = new Traverse\ParentNode($key, $newPath, $schema, $attribute);

                $out = [];
                foreach ($value as $i => $item) {
                    // A dynamic zone array can contain a `null` entry; pass nil entries through untouched (#24303)
                    if ($item === null) {
                        $out[] = null;
                        continue;
                    }
                    $component = is_array($item) ? ($item['__component'] ?? null) : null;
                    $out[] = $recurse(is_string($component) ? $getModel($component) : null, $withIndex($i), $item);
                }
                $copy[$key] = $out;
                $utils->replace($copy);

                continue;
            }
        }

        return $copy;
    }
}
