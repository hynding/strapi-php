<?php

declare(strict_types=1);

namespace Strapi\Utils\Traverse;

use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Operators;

/**
 * Port of packages/core/utils/src/traverse/query-filters.ts.
 *
 * `QueryFilters::traverse($visitor, ['schema' => ..., 'getModel' => ...], $filters)`; the curried
 * form `QueryFilters::create($visitor, $options)` returns `callable(mixed): mixed`.
 */
final class QueryFilters
{
    private static ?Factory $factory = null;

    /**
     * @param callable(VisitorOptions, VisitorUtils): void $visitor
     * @param array{schema: Schema|array<string, mixed>|null, getModel: callable, path?: Path|null, parent?: ParentNode|null} $options
     */
    public static function traverse(callable $visitor, array $options, mixed $filters): mixed
    {
        return self::factory()->traverse($visitor, $options, $filters);
    }

    /**
     * @param callable(VisitorOptions, VisitorUtils): void $visitor
     * @param array{schema: Schema|array<string, mixed>|null, getModel: callable, path?: Path|null, parent?: ParentNode|null} $options
     * @return \Closure(mixed): mixed
     */
    public static function create(callable $visitor, array $options): \Closure
    {
        return static fn (mixed $filters): mixed => self::traverse($visitor, $options, $filters);
    }

    /**
     * True if this object should be walked as a filter subtree (operators / attributes), not an opaque operand.
     *
     * @param array<array-key, mixed> $value
     * @param Schema|array<string, mixed>|null $schema
     */
    private static function isFilterLikeObject(array $value, Schema|array|null $schema): bool
    {
        foreach (array_keys($value) as $k) {
            if (Operators::isOperator((string) $k) || ContentTypes::attribute($schema, (string) $k) !== null) {
                return true;
            }
        }

        return false;
    }

    private static function isList(mixed $value): bool
    {
        return is_array($value) && $value !== [] && array_is_list($value);
    }

    public static function factory(): Factory
    {
        return self::$factory ??= Factory::create()
            // Intercept filters arrays and apply the traversal to each one individually
            ->intercept(
                self::isList(...),
                static function (callable $visitor, array $options, array $filters, \Closure $recurse): array {
                    $out = [];
                    foreach ($filters as $i => $filter) {
                        // only operators such as $and/$or can hold arrays: update the raw path, not the attribute path
                        $path = $options['path'] ?? null;
                        $newOptions = $options;
                        if ($path instanceof Path) {
                            $newOptions['path'] = $path->withRaw("{$path->raw}[{$i}]");
                        }
                        $res = $recurse($visitor, $newOptions, $filter);
                        if (is_array($res) && $res === []) {
                            continue;
                        }
                        $out[] = $res;
                    }

                    return $out;
                },
            )
            // Ignore non object filters and return the value as-is
            ->intercept(
                static fn (mixed $filters): bool => !is_array($filters),
                static fn (callable $v, array $o, mixed $filters): mixed => $filters,
            )
            // Parse object values
            ->parse(Factory::isObj(...), static fn (): array => Factory::objectParser())
            // Ignore null or undefined values
            ->ignore(static fn (Context $ctx): bool => $ctx->value === null)
            // Recursion on operators (non attributes)
            ->on(
                static fn (Context $ctx): bool => $ctx->attribute === null,
                static function (Context $ctx, TransformUtils $utils): void {
                    $parent = $ctx->asParent();
                    $value = $ctx->value;

                    // Operator operands that are plain objects (not arrays) are only traversed when they look like
                    // filter subtrees. Otherwise treat as opaque operands ($null booleans, dates, ...). $not is excluded.
                    if (
                        Operators::isOperator($ctx->key)
                        && $ctx->key !== '$not'
                        && is_array($value)
                        && !self::isList($value)
                        && $value !== []
                        && !self::isFilterLikeObject($value, $ctx->schema)
                    ) {
                        $utils->set($ctx->key, $value);

                        return;
                    }

                    $utils->set($ctx->key, $utils->recurse($ctx->visitor, ['schema' => $ctx->schema, 'path' => $ctx->path, 'getModel' => $ctx->getModel, 'parent' => $parent], $value));
                },
            )
            // Handle relation recursion
            ->onRelation(static function (Context $ctx, TransformUtils $utils): void {
                if (ContentTypes::isMorphRelationalAttribute($ctx->attribute)) {
                    return;
                }

                $targetSchema = $ctx->getModel((string) ($ctx->attribute['target'] ?? ''));
                $newValue = $utils->recurse($ctx->visitor, ['schema' => $targetSchema, 'path' => $ctx->path, 'getModel' => $ctx->getModel, 'parent' => $ctx->asParent()], $ctx->value);

                $utils->set($ctx->key, $newValue);
            })
            ->onComponent(static function (Context $ctx, TransformUtils $utils): void {
                $targetSchema = $ctx->getModel((string) ($ctx->attribute['component'] ?? ''));
                $newValue = $utils->recurse($ctx->visitor, ['schema' => $targetSchema, 'path' => $ctx->path, 'getModel' => $ctx->getModel, 'parent' => $ctx->asParent()], $ctx->value);

                $utils->set($ctx->key, $newValue);
            })
            // Handle media recursion
            ->onMedia(static function (Context $ctx, TransformUtils $utils): void {
                $targetSchema = $ctx->getModel('plugin::upload.file');
                $newValue = $utils->recurse($ctx->visitor, ['schema' => $targetSchema, 'path' => $ctx->path, 'getModel' => $ctx->getModel, 'parent' => $ctx->asParent()], $ctx->value);

                $utils->set($ctx->key, $newValue);
            })
            // Scalar fields: recurse into operator maps (e.g. { $contains: 'x' }) so visitors see nested keys.
            ->onAttribute(
                static fn (Context $ctx): bool => ContentTypes::isScalarAttribute($ctx->attribute) && is_array($ctx->value) && !self::isList($ctx->value),
                static function (Context $ctx, TransformUtils $utils): void {
                    $utils->set($ctx->key, $utils->recurse($ctx->visitor, ['schema' => $ctx->schema, 'path' => $ctx->path, 'getModel' => $ctx->getModel, 'parent' => $ctx->asParent()], $ctx->value));
                },
            );
    }
}
