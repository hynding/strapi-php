<?php

declare(strict_types=1);

namespace Strapi\Utils\Traverse;

use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Primitives\Objects;
use Strapi\Utils\SortQuery;

/** Port of packages/core/utils/src/traverse/query-sort.ts. */
final class QuerySort
{
    private const ORDER_VALUES = ['asc', 'desc'];

    private static ?Factory $factory = null;

    /**
     * @param callable(VisitorOptions, VisitorUtils): void $visitor
     * @param array{schema: Schema|array<string, mixed>|null, getModel: callable, path?: Path|null, parent?: ParentNode|null} $options
     */
    public static function traverse(callable $visitor, array $options, mixed $sort): mixed
    {
        return self::factory()->traverse($visitor, $options, $sort);
    }

    /**
     * @param callable(VisitorOptions, VisitorUtils): void $visitor
     * @param array{schema: Schema|array<string, mixed>|null, getModel: callable, path?: Path|null, parent?: ParentNode|null} $options
     * @return \Closure(mixed): mixed
     */
    public static function create(callable $visitor, array $options): \Closure
    {
        return static fn (mixed $sort): mixed => self::traverse($visitor, $options, $sort);
    }

    private static function isSortOrder(string $value): bool
    {
        return in_array(strtolower($value), self::ORDER_VALUES, true);
    }

    private static function isNestedSorts(mixed $value): bool
    {
        return is_string($value) && count(explode(',', $value)) > 1;
    }

    /** Meaningless sort arrays (e.g. qs `sort[]` → `[null]`) must not be walked as `{ '0': null }`. */
    private static function isMeaninglessSortArray(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && !SortQuery::hasSort($value);
    }

    /** @return list<string> */
    private static function tokenize(?string $value): array
    {
        $out = [];
        foreach (explode('.', $value ?? '') as $part) {
            foreach (explode(':', $part) as $sub) {
                $out[] = $sub;
            }
        }

        return $out;
    }

    /** @param list<string> $parts */
    private static function recompose(array $parts): ?string
    {
        if ($parts === []) {
            return null;
        }

        $acc = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if ($acc === '') {
                $acc = $part;
                continue;
            }
            $acc = self::isSortOrder($part) ? "{$acc}:{$part}" : "{$acc}.{$part}";
        }

        return $acc;
    }

    private static function mapRecurse(callable $visitor, array $options, array $items, \Closure $recurse): array
    {
        $out = [];
        foreach ($items as $nested) {
            $res = $recurse($visitor, $options, $nested);
            if (!Objects::isEmpty($res)) {
                $out[] = $res;
            }
        }

        return $out;
    }

    public static function factory(): Factory
    {
        return self::$factory ??= Factory::create()
            ->intercept(self::isMeaninglessSortArray(...), static fn (callable $v, array $o, mixed $sort): mixed => $sort)
            // String with chained sorts (foo,bar,foobar) => split, map(recurse), then recompose
            ->intercept(
                self::isNestedSorts(...),
                static function (callable $visitor, array $options, string $sort, \Closure $recurse): string {
                    $parts = array_map('trim', explode(',', $sort));

                    return implode(',', self::mapRecurse($visitor, $options, $parts, $recurse));
                },
            )
            // Array of strings ['foo', 'foo,bar'] => map(recurse), then filter out empty items
            ->intercept(
                Factory::isStringArray(...),
                static fn (callable $visitor, array $options, array $sort, \Closure $recurse): array => self::mapRecurse($visitor, $options, $sort, $recurse),
            )
            // Array of objects [{ foo: 'asc' }, { bar: 'desc', baz: 'asc' }] => map(recurse), then filter out empty items
            ->intercept(
                Factory::isObjectArray(...),
                static fn (callable $visitor, array $options, array $sort, \Closure $recurse): array => self::mapRecurse($visitor, $options, $sort, $recurse),
            )
            // Parse string values
            ->parse('is_string', static fn (): array => [
                'transform' => static fn (string $value): string => trim($value),
                'remove' => static function (string $key, ?string $data): ?string {
                    $root = self::tokenize($data)[0] ?? null;

                    return $root === $key ? null : $data;
                },
                'set' => static function (string $key, mixed $value, ?string $data): ?string {
                    $root = self::tokenize($data)[0] ?? null;

                    if ($root !== $key) {
                        return $data;
                    }

                    return $value === null ? $root : "{$root}.{$value}";
                },
                'keys' => static function (?string $data): array {
                    $v = self::tokenize($data)[0] ?? '';

                    return $v !== '' ? [$v] : [];
                },
                'get' => static function (string $key, ?string $data): ?string {
                    $tokens = self::tokenize($data);
                    $root = array_shift($tokens);

                    return $key === $root ? self::recompose($tokens) : null;
                },
            ])
            // Parse object values
            ->parse(Factory::isObj(...), static fn (): array => Factory::objectParser())
            // Handle deep sort on relation
            ->onRelation(static function (Context $ctx, TransformUtils $utils): void {
                if (ContentTypes::isMorphRelationalAttribute($ctx->attribute)) {
                    return;
                }

                $targetSchema = $ctx->getModel((string) ($ctx->attribute['target'] ?? ''));
                $newValue = $utils->recurse($ctx->visitor, ['schema' => $targetSchema, 'path' => $ctx->path, 'getModel' => $ctx->getModel, 'parent' => $ctx->asParent()], $ctx->value);

                $utils->set($ctx->key, $newValue);
            })
            // Handle deep sort on media
            ->onMedia(static function (Context $ctx, TransformUtils $utils): void {
                $targetSchema = $ctx->getModel('plugin::upload.file');
                $newValue = $utils->recurse($ctx->visitor, ['schema' => $targetSchema, 'path' => $ctx->path, 'getModel' => $ctx->getModel, 'parent' => $ctx->asParent()], $ctx->value);

                $utils->set($ctx->key, $newValue);
            })
            // Handle deep sort on components
            ->onComponent(static function (Context $ctx, TransformUtils $utils): void {
                $targetSchema = $ctx->getModel((string) ($ctx->attribute['component'] ?? ''));
                $newValue = $utils->recurse($ctx->visitor, ['schema' => $targetSchema, 'path' => $ctx->path, 'getModel' => $ctx->getModel, 'parent' => $ctx->asParent()], $ctx->value);

                $utils->set($ctx->key, $newValue);
            });
    }
}
