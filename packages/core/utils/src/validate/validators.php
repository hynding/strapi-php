<?php

declare(strict_types=1);

namespace Strapi\Utils\Validate;

use Strapi\Types\Schema\Schema;
use Strapi\Utils\Async;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Operators;
use Strapi\Utils\ParseType;
use Strapi\Utils\Primitives\Objects;
use Strapi\Utils\Traverse\ParentNode;
use Strapi\Utils\Traverse\QueryFields;
use Strapi\Utils\Traverse\QueryFilters;
use Strapi\Utils\Traverse\QueryPopulate;
use Strapi\Utils\Traverse\QuerySort;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;
use Strapi\Utils\Validate\Visitors\ThrowDynamicZones;
use Strapi\Utils\Validate\Visitors\ThrowMorphToRelations;
use Strapi\Utils\Validate\Visitors\ThrowPassword;
use Strapi\Utils\Validate\Visitors\ThrowPrivate;

/**
 * Port of packages/core/utils/src/validate/validators.ts: the default validation pipelines. Every
 * function throws a {@see \Strapi\Utils\Errors\ValidationError} on the first invalid key and
 * otherwise returns the (possibly normalized) value.
 *
 * @phpstan-type Ctx array{schema: Schema|array<string, mixed>|null, getModel: callable(string): (Schema|array<string, mixed>|null), parent?: ParentNode|null, path?: \Strapi\Utils\Traverse\Path|null}
 * @phpstan-type Includes array{fields?: list<string>, sort?: list<string>, filters?: list<string>, populate?: list<string>}
 */
final class Validators
{
    public const FILTER_TRAVERSALS = ['nonAttributesOperators', 'dynamicZones', 'morphRelations', 'passwords', 'private'];

    public const SORT_TRAVERSALS = ['nonAttributesOperators', 'dynamicZones', 'morphRelations', 'passwords', 'private', 'nonScalarEmptyKeys'];

    public const FIELDS_TRAVERSALS = ['scalarAttributes', 'privateFields', 'passwordFields'];

    public const POPULATE_TRAVERSALS = ['nonAttributesOperators', 'private'];

    /** Query params that only apply at the root of a query (#21911). */
    private const ROOT_ONLY_QUERY_PARAMS = ['status', 'publicationFilter', 'hasPublishedVersion'];

    /** @param Ctx $ctx */
    private static function assertSchema(array $ctx, string $fn): void
    {
        if (($ctx['schema'] ?? null) === null) {
            throw new \LogicException("Missing schema in {$fn}");
        }
    }

    /**
     * @param Ctx $ctx
     * @param list<string> $include
     */
    public static function validateFilters(array $ctx, mixed $filters, array $include): mixed
    {
        self::assertSchema($ctx, 'defaultValidateFilters');

        $functionsToApply = [];

        if (in_array('nonAttributesOperators', $include, true)) {
            $functionsToApply[] = QueryFilters::create(static function (VisitorOptions $o, VisitorUtils $u): void {
                if (in_array($o->key, ContentTypes::ID_FIELDS, true)) {
                    return;
                }
                if ($o->attribute === null && !Operators::isOperator($o->key)) {
                    Utils::throwInvalidKey(['key' => $o->key, 'path' => $o->path->attribute]);
                }
            }, $ctx);
        }
        if (in_array('dynamicZones', $include, true)) {
            $functionsToApply[] = QueryFilters::create(new ThrowDynamicZones(), $ctx);
        }
        if (in_array('morphRelations', $include, true)) {
            $functionsToApply[] = QueryFilters::create(new ThrowMorphToRelations(), $ctx);
        }
        if (in_array('passwords', $include, true)) {
            $functionsToApply[] = QueryFilters::create(new ThrowPassword(), $ctx);
        }
        if (in_array('private', $include, true)) {
            $functionsToApply[] = QueryFilters::create(new ThrowPrivate(), $ctx);
        }

        if ($functionsToApply === []) {
            return $filters;
        }

        return Async::pipe(...$functionsToApply)($filters);
    }

    /** @param Ctx $ctx */
    public static function defaultValidateFilters(array $ctx, mixed $filters): mixed
    {
        return self::validateFilters($ctx, $filters, self::FILTER_TRAVERSALS);
    }

    /**
     * @param Ctx $ctx
     * @param list<string> $include
     */
    public static function validateSort(array $ctx, mixed $sort, array $include): mixed
    {
        self::assertSchema($ctx, 'defaultValidateSort');

        $functionsToApply = [];
        $schema = $ctx['schema'];

        if (in_array('nonAttributesOperators', $include, true)) {
            $functionsToApply[] = QuerySort::create(static function (VisitorOptions $o, VisitorUtils $u) use ($schema): void {
                if (in_array($o->key, ContentTypes::ID_FIELDS, true)) {
                    return;
                }
                // status is a virtual sort key for D&P-enabled content types
                if ($o->key === 'status' && ContentTypes::hasDraftAndPublish($schema)) {
                    return;
                }
                if ($o->attribute === null) {
                    Utils::throwInvalidKey(['key' => $o->key, 'path' => $o->path->attribute]);
                }
            }, $ctx);
        }
        if (in_array('dynamicZones', $include, true)) {
            $functionsToApply[] = QuerySort::create(new ThrowDynamicZones(), $ctx);
        }
        if (in_array('morphRelations', $include, true)) {
            $functionsToApply[] = QuerySort::create(new ThrowMorphToRelations(), $ctx);
        }
        if (in_array('passwords', $include, true)) {
            $functionsToApply[] = QuerySort::create(new ThrowPassword(), $ctx);
        }
        if (in_array('private', $include, true)) {
            $functionsToApply[] = QuerySort::create(new ThrowPrivate(), $ctx);
        }
        if (in_array('nonScalarEmptyKeys', $include, true)) {
            $functionsToApply[] = QuerySort::create(static function (VisitorOptions $o, VisitorUtils $u): void {
                if (in_array($o->key, ContentTypes::ID_FIELDS, true)) {
                    return;
                }
                if (!ContentTypes::isScalarAttribute($o->attribute) && Objects::isEmpty($o->value)) {
                    Utils::throwInvalidKey(['key' => $o->key, 'path' => $o->path->attribute]);
                }
            }, $ctx);
        }

        if ($functionsToApply === []) {
            return $sort;
        }

        return Async::pipe(...$functionsToApply)($sort);
    }

    /** @param Ctx $ctx */
    public static function defaultValidateSort(array $ctx, mixed $sort): mixed
    {
        return self::validateSort($ctx, $sort, self::SORT_TRAVERSALS);
    }

    /**
     * @param Ctx $ctx
     * @param list<string> $include
     */
    public static function validateFields(array $ctx, mixed $fields, array $include): mixed
    {
        self::assertSchema($ctx, 'defaultValidateFields');

        $functionsToApply = [];

        if (in_array('scalarAttributes', $include, true)) {
            $functionsToApply[] = QueryFields::create(static function (VisitorOptions $o, VisitorUtils $u): void {
                if (in_array($o->key, ContentTypes::ID_FIELDS, true)) {
                    return;
                }
                if ($o->attribute === null || !ContentTypes::isScalarAttribute($o->attribute)) {
                    Utils::throwInvalidKey(['key' => $o->key, 'path' => $o->path->attribute]);
                }
            }, $ctx);
        }
        if (in_array('privateFields', $include, true)) {
            $functionsToApply[] = QueryFields::create(new ThrowPrivate(), $ctx);
        }
        if (in_array('passwordFields', $include, true)) {
            $functionsToApply[] = QueryFields::create(new ThrowPassword(), $ctx);
        }

        if ($functionsToApply === []) {
            return $fields;
        }

        return Async::pipe(...$functionsToApply)($fields);
    }

    /** @param Ctx $ctx */
    public static function defaultValidateFields(array $ctx, mixed $fields): mixed
    {
        return self::validateFields($ctx, $fields, self::FIELDS_TRAVERSALS);
    }

    /** @param array<string, mixed>|null $attribute */
    private static function isMorphLikeAttribute(?array $attribute): bool
    {
        return ContentTypes::isDynamicZoneAttribute($attribute) || ContentTypes::isMorphToRelationalAttribute($attribute);
    }

    /** @param array<string, mixed>|null $attribute */
    private static function isPopulatableAttribute(?array $attribute): bool
    {
        return $attribute !== null && in_array($attribute['type'] ?? null, ['relation', 'dynamiczone', 'component', 'media'], true);
    }

    private static function isDotNotationPopulate(mixed $populate): bool
    {
        if ($populate === '*') {
            return false;
        }
        if (is_string($populate)) {
            return true;
        }

        return is_array($populate) && $populate !== [] && array_is_list($populate) && array_reduce($populate, static fn (bool $carry, mixed $p): bool => $carry && is_string($p), true);
    }

    /**
     * @param string|list<string> $populate
     * @return list<string>
     */
    private static function flattenDotPopulatePaths(string|array $populate): array
    {
        $items = is_array($populate) ? $populate : [$populate];
        $out = [];
        foreach ($items as $item) {
            foreach (explode(',', $item) as $segment) {
                $segment = trim($segment);
                if ($segment !== '') {
                    $out[] = $segment;
                }
            }
        }

        return $out;
    }

    /**
     * @param array{schema: Schema|array<string, mixed>|null, getModel: callable(string): (Schema|array<string, mixed>|null)} $ctx
     * @param list<string> $segments
     */
    private static function validatePopulateDotPathSegments(array $ctx, array $segments, string $pathPrefix): void
    {
        if ($segments === []) {
            return;
        }

        $schema = $ctx['schema'];
        $getModel = $ctx['getModel'];
        $head = array_shift($segments);
        $tail = $segments;
        $fullPath = $pathPrefix !== '' ? "{$pathPrefix}.{$head}" : $head;
        $attribute = ContentTypes::attribute($schema, $head);

        if ($attribute === null || !self::isPopulatableAttribute($attribute)) {
            Utils::throwInvalidKey(['key' => $head, 'path' => $fullPath]);
        }

        if ($tail === []) {
            return;
        }

        $type = $attribute['type'] ?? null;

        if ($type === 'component') {
            $component = $attribute['component'] ?? null;
            if (!is_string($component) || $component === '') {
                Utils::throwInvalidKey(['key' => $head, 'path' => $fullPath]);
            }
            self::validatePopulateDotPathSegments(['schema' => $getModel($component), 'getModel' => $getModel], $tail, $fullPath);

            return;
        }

        if ($type === 'relation') {
            if (self::isMorphLikeAttribute($attribute)) {
                Utils::throwInvalidKey(['key' => $head, 'path' => $fullPath]);
            }
            $target = $attribute['target'] ?? null;
            if (!is_string($target) || $target === '') {
                Utils::throwInvalidKey(['key' => $head, 'path' => $fullPath]);
            }
            self::validatePopulateDotPathSegments(['schema' => $getModel($target), 'getModel' => $getModel], $tail, $fullPath);

            return;
        }

        if ($type === 'media') {
            Utils::throwInvalidKey(['key' => $head, 'path' => $fullPath]);
        }

        if ($type === 'dynamiczone') {
            $nextSegment = array_shift($tail);
            $rest = $tail;
            $nextPath = "{$fullPath}.{$nextSegment}";
            $candidates = [];
            foreach ((array) ($attribute['components'] ?? []) as $uid) {
                $model = $getModel((string) $uid);
                if ($model !== null && ContentTypes::attribute($model, (string) $nextSegment) !== null) {
                    $candidates[] = $model;
                }
            }

            if ($candidates === []) {
                Utils::throwInvalidKey(['key' => (string) $nextSegment, 'path' => $nextPath]);
            }

            $validated = false;

            foreach ($candidates as $componentSchema) {
                $nestedAttribute = ContentTypes::attribute($componentSchema, (string) $nextSegment);

                try {
                    if ($rest === []) {
                        if ($nestedAttribute !== null) {
                            $validated = true;
                            break;
                        }
                    } elseif (($nestedAttribute['type'] ?? null) === 'component' && !empty($nestedAttribute['component'])) {
                        self::validatePopulateDotPathSegments(['schema' => $getModel((string) $nestedAttribute['component']), 'getModel' => $getModel], $rest, $nextPath);
                        $validated = true;
                        break;
                    } elseif (($nestedAttribute['type'] ?? null) === 'relation' && !self::isMorphLikeAttribute($nestedAttribute) && !empty($nestedAttribute['target'])) {
                        self::validatePopulateDotPathSegments(['schema' => $getModel((string) $nestedAttribute['target']), 'getModel' => $getModel], $rest, $nextPath);
                        $validated = true;
                        break;
                    }
                } catch (\Throwable) {
                    // Try the next dynamic-zone component type.
                }
            }

            if (!$validated) {
                Utils::throwInvalidKey(['key' => (string) $nextSegment, 'path' => $nextPath]);
            }
        }
    }

    /**
     * @param array{schema: Schema|array<string, mixed>|null, getModel: callable(string): (Schema|array<string, mixed>|null)} $ctx
     * @param string|list<string> $populate
     */
    private static function validatePopulateDotPaths(array $ctx, string|array $populate): void
    {
        foreach (self::flattenDotPopulatePaths($populate) as $path) {
            $segments = array_values(array_filter(array_map('trim', explode('.', $path)), static fn (string $s): bool => $s !== ''));
            self::validatePopulateDotPathSegments($ctx, $segments, '');
        }
    }

    private static function validateMorphLikeNestedPopulate(mixed $populateValue, ?string $path): void
    {
        // Keep in sync with convert-query-params polymorphic nested populate handling.
        if ($populateValue !== null && $populateValue !== '*') {
            Utils::throwInvalidKey(['key' => 'populate', 'path' => $path]);
        }
    }

    /**
     * @param Ctx $ctx
     * @param Includes $includes
     */
    public static function validatePopulate(array $ctx, mixed $populate, array $includes): mixed
    {
        self::assertSchema($ctx, 'defaultValidatePopulate');

        $populateIncludes = $includes['populate'] ?? [];

        // qs turns `populate[0..100]` beyond arrayLimit into a numeric-key object; upstream rejects that shape
        // before anything else. In PHP it is indistinguishable from a 101-item list, so check it first.
        if (QueryPopulate::isQsArrayLimitPopulateObject($populate)) {
            QueryPopulate::throwQsArrayLimitPopulateError(count($populate));
        }

        if (self::isDotNotationPopulate($populate)) {
            /** @var string|list<string> $populate */
            self::validatePopulateDotPaths(['schema' => $ctx['schema'], 'getModel' => $ctx['getModel']], $populate);

            if (in_array('private', $populateIncludes, true)) {
                return QueryPopulate::traverse(new ThrowPrivate(), $ctx, $populate);
            }

            return $populate;
        }

        $functionsToApply = [];

        // Always include the main traversal function
        $functionsToApply[] = QueryPopulate::create(
            static function (VisitorOptions $o, VisitorUtils $u) use ($includes, $populateIncludes): void {
                $key = $o->key;
                $value = $o->value;
                $attribute = $o->attribute;

                // The parent will not be an attribute when it is a "populate" / "filters" / "sort" ... key.
                // Only in those scenarios the node will be an attribute (supports attributes named "filters").
                if ($o->parent?->attribute === null && $attribute !== null) {
                    $isPopulatableAttribute = in_array($attribute['type'] ?? null, ['relation', 'dynamiczone', 'component', 'media'], true);

                    if (self::isMorphLikeAttribute($attribute) && is_array($value) && array_key_exists('populate', $value)) {
                        self::validateMorphLikeNestedPopulate($value['populate'], $o->path->raw);
                    }

                    if (!$isPopulatableAttribute) {
                        Utils::throwInvalidKey(['key' => $key, 'path' => $o->path->raw]);
                    }

                    return;
                }

                // If we're looking at a populate fragment, ensure its target is valid
                if ($key === 'on') {
                    if (!is_array($value)) {
                        Utils::throwInvalidKey(['key' => $key, 'path' => $o->path->raw]);
                    }

                    foreach (array_keys($value) as $target) {
                        if ($o->getModel((string) $target) === null) {
                            Utils::throwInvalidKey(['key' => (string) $target, 'path' => "{$o->path->raw}.{$target}"]);
                        }
                    }

                    return;
                }

                // Ignore plain wildcards
                if ($key === '' && $value === '*') {
                    return;
                }

                // Ensure count is a boolean
                if ($key === 'count') {
                    if (ParseType::isBooleanLike($value)) {
                        return;
                    }
                    Utils::throwInvalidKey(['key' => $key, 'path' => $o->path->attribute]);
                }

                // Allowed boolean-like keywords should be ignored.
                if (ParseType::isBooleanLike($key)) {
                    return;
                }

                $nestedCtx = ['schema' => $o->schema, 'getModel' => $o->getModel];

                if ($key === 'sort') {
                    $u->set($key, self::validateSort($nestedCtx, $value, $includes['sort'] ?? self::SORT_TRAVERSALS));

                    return;
                }

                if ($key === 'filters') {
                    $u->set($key, self::validateFilters($nestedCtx, $value, $includes['filters'] ?? self::FILTER_TRAVERSALS));

                    return;
                }

                if ($key === 'fields') {
                    $u->set($key, self::validateFields($nestedCtx, $value, $includes['fields'] ?? self::FIELDS_TRAVERSALS));

                    return;
                }

                if ($key === 'populate') {
                    $u->set($key, self::validatePopulate([
                        ...$nestedCtx,
                        'parent' => new ParentNode($key, $o->path, $o->schema, $attribute),
                        'path' => $o->path,
                    ], $value, $includes));

                    return;
                }

                // Throw an error if non-attribute operators are included in the populate array
                if (in_array('nonAttributesOperators', $populateIncludes, true)) {
                    Utils::throwInvalidKey([
                        'key' => $key,
                        'path' => $o->path->attribute,
                        'reason' => in_array($key, self::ROOT_ONLY_QUERY_PARAMS, true)
                            ? "{$key} is only accepted at the root of the query, and it also applies to populated relations"
                            : null,
                    ]);
                }
            },
            $ctx,
        );

        if (in_array('private', $populateIncludes, true)) {
            $functionsToApply[] = QueryPopulate::create(new ThrowPrivate(), $ctx);
        }

        return Async::pipe(...$functionsToApply)($populate);
    }

    /** @param Ctx $ctx */
    public static function defaultValidatePopulate(array $ctx, mixed $populate): mixed
    {
        self::assertSchema($ctx, 'defaultValidatePopulate');

        return self::validatePopulate($ctx, $populate, [
            'filters' => self::FILTER_TRAVERSALS,
            'sort' => self::SORT_TRAVERSALS,
            'fields' => self::FIELDS_TRAVERSALS,
            'populate' => self::POPULATE_TRAVERSALS,
        ]);
    }
}
