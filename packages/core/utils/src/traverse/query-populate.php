<?php

declare(strict_types=1);

namespace Strapi\Utils\Traverse;

use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Primitives\Objects;

/** Port of packages/core/utils/src/traverse/query-populate.ts. */
final class QueryPopulate
{
    public const DEFAULT_QS_ARRAY_LIMIT = 100;

    private static ?Factory $factory = null;

    /**
     * @param callable(VisitorOptions, VisitorUtils): void $visitor
     * @param array{schema: Schema|array<string, mixed>|null, getModel: callable, path?: Path|null, parent?: ParentNode|null} $options
     */
    public static function traverse(callable $visitor, array $options, mixed $populate): mixed
    {
        return self::factory()->traverse($visitor, $options, $populate);
    }

    /**
     * @param callable(VisitorOptions, VisitorUtils): void $visitor
     * @param array{schema: Schema|array<string, mixed>|null, getModel: callable, path?: Path|null, parent?: ParentNode|null} $options
     * @return \Closure(mixed): mixed
     */
    public static function create(callable $visitor, array $options): \Closure
    {
        return static fn (mixed $populate): mixed => self::traverse($visitor, $options, $populate);
    }

    /**
     * The `arrayLimit` the query string was parsed with (`strapi::query`'s config), set by that
     * middleware: a list up to it is a real array, not a `qs` overflow object.
     */
    private static int $qsArrayLimit = self::DEFAULT_QS_ARRAY_LIMIT;

    public static function setQsArrayLimit(int $arrayLimit): void
    {
        self::$qsArrayLimit = $arrayLimit;
    }

    /**
     * Detects objects with consecutive numeric string keys and string values — the shape `qs`
     * produces when indexed array notation exceeds `arrayLimit` (#25632). In PHP such a value is a
     * plain list longer than the limit the query was parsed with; like upstream, it is rejected
     * only past {@see DEFAULT_QS_ARRAY_LIMIT} entries.
     */
    public static function isQsArrayLimitPopulateObject(mixed $value): bool
    {
        if (!is_array($value) || $value === []) {
            return false;
        }

        $keys = array_keys($value);
        if (count($keys) <= self::DEFAULT_QS_ARRAY_LIMIT || count($keys) <= self::$qsArrayLimit) {
            return false;
        }

        foreach ($keys as $index => $key) {
            if ((string) $key !== (string) $index) {
                return false;
            }
        }

        foreach ($value as $entry) {
            if (!is_string($entry)) {
                return false;
            }
        }

        return true;
    }

    public static function throwQsArrayLimitPopulateError(int $entryCount): never
    {
        throw new ValidationError(
            "Too many populate entries ({$entryCount}). The maximum number of populate entries when using array notation is " . self::DEFAULT_QS_ARRAY_LIMIT . '. '
            . 'Consider using object notation (populate[field]=true), nested population, or reducing the number of fields.'
        );
    }

    /** @return callable(Context): bool */
    private static function isKeyword(string $keyword): callable
    {
        return static fn (Context $ctx): bool => $ctx->attribute === null && $ctx->key === $keyword;
    }

    private static function isWildcard(mixed $value): bool
    {
        return $value === '*';
    }

    private static function isPopulateString(mixed $value): bool
    {
        return is_string($value) && !self::isWildcard($value);
    }

    public static function factory(): Factory
    {
        return self::$factory ??= Factory::create()
            ->intercept(self::isQsArrayLimitPopulateObject(...), static function (callable $v, array $o, array $populate): never {
                self::throwQsArrayLimitPopulateError(count($populate));
            })
            ->intercept(self::isPopulateString(...), static function (callable $visitor, array $options, string $populate, \Closure $recurse): mixed {
                // Ensure the populate clause is in the extended format ({ populate: { ... } }), not just a string,
                // for a consistent "parent" structure of each nested populate clause
                $populateObject = self::pathsToObjectPopulate([$populate]);
                $traversedPopulate = $recurse($visitor, $options, $populateObject);
                $paths = is_array($traversedPopulate) ? self::objectPopulateToPaths($traversedPopulate) : null;

                // Dot notation cannot represent polymorphic `on` fragments. Keep the object form when a visitor adds one.
                return $paths !== null ? ($paths[0] ?? null) : $traversedPopulate;
            })
            // Array of strings ['foo', 'bar.baz'] => traverse as one object, then serialize when possible.
            // PHP-only: an empty array is also the empty object the conversion produces, so it is
            // traversed as an object (otherwise it would be intercepted again, forever).
            ->intercept(static fn (mixed $value): bool => $value !== [] && Factory::isStringArray($value), static function (callable $visitor, array $options, array $populate, \Closure $recurse): mixed {
                $populateObject = self::pathsToObjectPopulate(array_values(array_filter($populate, 'is_string')));
                $traversedPopulate = $recurse($visitor, $options, $populateObject);
                $paths = is_array($traversedPopulate) ? self::objectPopulateToPaths($traversedPopulate) : null;

                return $paths ?? $traversedPopulate;
            })
            // for wildcard, generate custom utilities to modify the values
            ->parse(self::isWildcard(...), static fn (): array => [
                'transform' => static fn (mixed $value): mixed => $value,
                // '*' isn't a key/value structure: regardless of the key, return the data ('*')
                'get' => static fn (string $key, mixed $data): mixed => $data,
                // use `value` as the new `data`
                'set' => static fn (string $key, mixed $value, mixed $data): mixed => $value,
                // simulate one key ('') to enable the traversal
                'keys' => static fn (mixed $data): array => [''],
                // Removing '*' means setting it to undefined
                'remove' => static fn (string $key, mixed $data): mixed => null,
            ])
            // Parse string values
            ->parse('is_string', static fn (): array => [
                'transform' => static fn (string $value): string => trim($value),
                'remove' => static function (string $key, ?string $data): ?string {
                    $root = explode('.', $data ?? '')[0];

                    return $root === $key ? null : $data;
                },
                'set' => static function (string $key, mixed $value, ?string $data): ?string {
                    $root = explode('.', $data ?? '')[0];

                    if ($root !== $key) {
                        return $data;
                    }

                    return $value === null || Objects::isEmpty($value) ? $root : "{$root}." . (is_array($value) ? implode('.', $value) : (string) $value);
                },
                'keys' => static function (?string $data): array {
                    $v = explode('.', $data ?? '')[0];

                    return $v !== '' ? [$v] : [];
                },
                'get' => static function (string $key, ?string $data): ?string {
                    $parts = explode('.', $data ?? '');
                    $root = array_shift($parts);

                    return $key === $root ? implode('.', $parts) : null;
                },
            ])
            // Parse object values
            ->parse(Factory::isObj(...), static fn (): array => Factory::objectParser())
            ->ignore(static function (Context $ctx): bool {
                // Don't recurse using traversePopulate for query keywords: visitors handle them with the appropriate
                // traversal (sort, filters, fields). When a keyword also exists as an attribute, still treat it as a
                // keyword inside a nested populate context (parent is an attribute) — issue #21338.
                return in_array($ctx->key, ['sort', 'filters', 'fields'], true)
                    && ($ctx->attribute === null || $ctx->parent?->attribute !== null);
            })
            // Handle recursion on populate."populate"
            ->on(self::isKeyword('populate'), static function (Context $ctx, TransformUtils $utils): void {
                $newValue = $utils->recurse($ctx->visitor, ['schema' => $ctx->schema, 'path' => $ctx->path, 'getModel' => $ctx->getModel, 'parent' => $ctx->asParent()], $ctx->value);

                $utils->set($ctx->key, $newValue);
            })
            ->on(self::isKeyword('on'), static function (Context $ctx, TransformUtils $utils): void {
                if (!is_array($ctx->value)) {
                    return;
                }

                $newOn = [];
                foreach ($ctx->value as $uid => $subPopulate) {
                    $uid = (string) $uid;
                    $model = $ctx->getModel($uid);
                    $newPath = $ctx->path->withRaw("{$ctx->path->raw}[{$uid}]");

                    $newOn[$uid] = $utils->recurse($ctx->visitor, ['schema' => $model, 'path' => $newPath, 'getModel' => $ctx->getModel, 'parent' => $ctx->parent], $subPopulate);
                }

                $utils->set($ctx->key, $newOn);
            })
            // Handle populate on relation
            ->onRelation(static function (Context $ctx, TransformUtils $utils): void {
                $value = $ctx->value;
                if ($value === null) {
                    return;
                }

                $parent = $ctx->asParent();

                if (ContentTypes::isMorphToRelationalAttribute($ctx->attribute)) {
                    // Don't traverse values that cannot be parsed
                    if (!is_array($value) || !isset($value['on']) || !is_array($value['on'])) {
                        return;
                    }

                    // If there is a populate fragment defined, traverse it
                    $newValue = $utils->recurse($ctx->visitor, ['schema' => $ctx->schema, 'path' => $ctx->path, 'getModel' => $ctx->getModel, 'parent' => $parent], ['on' => $value['on']]);

                    $utils->set($ctx->key, array_merge($value, is_array($newValue) ? $newValue : []));

                    return;
                }

                $targetSchema = $ctx->getModel((string) ($ctx->attribute['target'] ?? ''));
                $newValue = $utils->recurse($ctx->visitor, ['schema' => $targetSchema, 'path' => $ctx->path, 'getModel' => $ctx->getModel, 'parent' => $parent], $value);

                $utils->set($ctx->key, $newValue);
            })
            // Handle populate on media
            ->onMedia(static function (Context $ctx, TransformUtils $utils): void {
                if ($ctx->value === null) {
                    return;
                }

                $targetSchema = $ctx->getModel('plugin::upload.file');
                $newValue = $utils->recurse($ctx->visitor, ['schema' => $targetSchema, 'path' => $ctx->path, 'getModel' => $ctx->getModel, 'parent' => $ctx->asParent()], $ctx->value);

                $utils->set($ctx->key, $newValue);
            })
            // Handle populate on components
            ->onComponent(static function (Context $ctx, TransformUtils $utils): void {
                if ($ctx->value === null) {
                    return;
                }

                $targetSchema = $ctx->getModel((string) ($ctx->attribute['component'] ?? ''));
                $newValue = $utils->recurse($ctx->visitor, ['schema' => $targetSchema, 'path' => $ctx->path, 'getModel' => $ctx->getModel, 'parent' => $ctx->asParent()], $ctx->value);

                $utils->set($ctx->key, $newValue);
            })
            // Handle populate on dynamic zones
            ->onDynamicZone(static function (Context $ctx, TransformUtils $utils): void {
                $value = $ctx->value;
                if ($value === null || !is_array($value)) {
                    return;
                }

                // Handle fragment syntax
                if (isset($value['on']) && $value['on']) {
                    $newOn = $utils->recurse($ctx->visitor, ['schema' => $ctx->schema, 'path' => $ctx->path, 'getModel' => $ctx->getModel, 'parent' => $ctx->asParent()], ['on' => $value['on']]);

                    $utils->set($ctx->key, $newOn);
                }
            });
    }

    /**
     * `{ a: true, b: { populate: { c: true } } }` → `['a', 'b.c']`, or null when a node is neither
     * `true` nor a `{ populate }` object (e.g. an `on` fragment was added).
     *
     * @param array<string, mixed> $input
     * @return list<string>|null
     */
    public static function objectPopulateToPaths(array $input): ?array
    {
        $paths = [];

        $walk = static function (array $currentObj, string $parentPath) use (&$paths, &$walk): bool {
            foreach ($currentObj as $key => $value) {
                $currentPath = $parentPath !== '' ? "{$parentPath}.{$key}" : (string) $key;
                if ($value === true) {
                    $paths[] = $currentPath;
                } else {
                    $nestedPopulate = is_array($value) ? ($value['populate'] ?? null) : null;

                    if (!is_array($nestedPopulate) || !$walk($nestedPopulate, $currentPath)) {
                        return false;
                    }
                }
            }

            return true;
        };

        if (!$walk($input, '')) {
            return null;
        }

        return $paths;
    }

    /**
     * `['a', 'b.c']` → `{ a: true, b: { populate: { c: true } } }`.
     *
     * @param list<string> $input
     * @return array<string, mixed>
     */
    public static function pathsToObjectPopulate(array $input): array
    {
        $result = [];

        $walk = static function (array &$object, array $keys) use (&$walk): void {
            $first = array_shift($keys);
            if ($keys === []) {
                $object[$first] = true;
            } else {
                if (!isset($object[$first]) || is_bool($object[$first])) {
                    $object[$first] = ['populate' => []];
                }
                $walk($object[$first]['populate'], $keys);
            }
        };

        foreach ($input as $clause) {
            $walk($result, explode('.', $clause));
        }

        return $result;
    }
}
