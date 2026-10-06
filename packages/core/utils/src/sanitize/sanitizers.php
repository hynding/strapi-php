<?php

declare(strict_types=1);

namespace Strapi\Utils\Sanitize;

use Strapi\Types\Schema\Schema;
use Strapi\Utils\Async;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Operators;
use Strapi\Utils\Primitives\Objects;
use Strapi\Utils\Sanitize\Visitors\ExpandWildcardPopulate;
use Strapi\Utils\Sanitize\Visitors\RemoveDynamicZones;
use Strapi\Utils\Sanitize\Visitors\RemoveMorphToRelations;
use Strapi\Utils\Sanitize\Visitors\RemovePassword;
use Strapi\Utils\Sanitize\Visitors\RemovePrivate;
use Strapi\Utils\Traverse\ParentNode;
use Strapi\Utils\Traverse\QueryFields;
use Strapi\Utils\Traverse\QueryFilters;
use Strapi\Utils\Traverse\QueryPopulate;
use Strapi\Utils\Traverse\QuerySort;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;
use Strapi\Utils\TraverseEntity;

/**
 * Port of packages/core/utils/src/sanitize/sanitizers.ts: the default sanitization pipelines.
 *
 * @phpstan-type Ctx array{schema: Schema|array<string, mixed>|null, getModel: callable(string): (Schema|array<string, mixed>|null), parent?: ParentNode|null}
 */
final class Sanitizers
{
    /** @param Ctx $ctx */
    private static function assertSchema(array $ctx, string $fn): void
    {
        if (($ctx['schema'] ?? null) === null) {
            throw new \LogicException("Missing schema in {$fn}");
        }
    }

    /**
     * @param Ctx $ctx
     * @return \Closure(mixed): mixed
     */
    public static function sanitizePasswords(array $ctx): \Closure
    {
        return static function (mixed $entity) use ($ctx): mixed {
            self::assertSchema($ctx, 'sanitizePasswords');

            return TraverseEntity::traverse(new RemovePassword(), $ctx, $entity);
        };
    }

    /** @param Ctx $ctx */
    public static function defaultSanitizeOutput(array $ctx, mixed $entity): mixed
    {
        self::assertSchema($ctx, 'defaultSanitizeOutput');

        $removePassword = new RemovePassword();
        $removePrivate = new RemovePrivate();

        return TraverseEntity::traverse(
            static function (VisitorOptions $options, VisitorUtils $utils) use ($removePassword, $removePrivate): void {
                $removePassword($options, $utils);
                $removePrivate($options, $utils);
            },
            $ctx,
            $entity,
        );
    }

    /** @param Ctx $ctx */
    public static function defaultSanitizeFilters(array $ctx, mixed $filters): mixed
    {
        self::assertSchema($ctx, 'defaultSanitizeFilters');

        return Async::pipe(
            // Remove keys that are not attributes or valid operators
            QueryFilters::create(static function (VisitorOptions $o, VisitorUtils $u): void {
                if (in_array($o->key, ContentTypes::ID_FIELDS, true)) {
                    return;
                }
                if ($o->attribute === null && !Operators::isOperator($o->key)) {
                    $u->remove($o->key);
                }
            }, $ctx),
            QueryFilters::create(new RemoveDynamicZones(), $ctx),
            QueryFilters::create(new RemoveMorphToRelations(), $ctx),
            QueryFilters::create(new RemovePassword(), $ctx),
            QueryFilters::create(new RemovePrivate(), $ctx),
            // Remove empty plain objects and empty arrays (not other "empty" values such as dates)
            QueryFilters::create(static function (VisitorOptions $o, VisitorUtils $u): void {
                if (is_array($o->value) && $o->value === []) {
                    $u->remove($o->key);
                }
            }, $ctx),
        )($filters);
    }

    /** @param Ctx $ctx */
    public static function defaultSanitizeSort(array $ctx, mixed $sort): mixed
    {
        self::assertSchema($ctx, 'defaultSanitizeSort');

        return Async::pipe(
            // Remove non attribute keys
            QuerySort::create(static function (VisitorOptions $o, VisitorUtils $u): void {
                if (in_array($o->key, ContentTypes::ID_FIELDS, true)) {
                    return;
                }
                if ($o->attribute === null) {
                    $u->remove($o->key);
                }
            }, $ctx),
            QuerySort::create(new RemoveDynamicZones(), $ctx),
            QuerySort::create(new RemoveMorphToRelations(), $ctx),
            QuerySort::create(new RemovePrivate(), $ctx),
            QuerySort::create(new RemovePassword(), $ctx),
            // Remove keys for empty non-scalar values
            QuerySort::create(static function (VisitorOptions $o, VisitorUtils $u): void {
                if (in_array($o->key, ContentTypes::ID_FIELDS, true)) {
                    return;
                }
                if (!ContentTypes::isScalarAttribute($o->attribute) && Objects::isEmpty($o->value)) {
                    $u->remove($o->key);
                }
            }, $ctx),
        )($sort);
    }

    /** @param Ctx $ctx */
    public static function defaultSanitizeFields(array $ctx, mixed $fields): mixed
    {
        self::assertSchema($ctx, 'defaultSanitizeFields');

        return Async::pipe(
            // Only keep scalar attributes
            QueryFields::create(static function (VisitorOptions $o, VisitorUtils $u): void {
                if (in_array($o->key, ContentTypes::ID_FIELDS, true)) {
                    return;
                }
                if ($o->attribute === null || !ContentTypes::isScalarAttribute($o->attribute)) {
                    $u->remove($o->key);
                }
            }, $ctx),
            QueryFields::create(new RemovePrivate(), $ctx),
            QueryFields::create(new RemovePassword(), $ctx),
            // Remove nil values from fields array
            static fn (mixed $value): mixed => is_array($value) && array_is_list($value)
                ? array_values(array_filter($value, static fn (mixed $field): bool => $field !== null))
                : $value,
        )($fields);
    }

    /** @param Ctx $ctx */
    public static function defaultSanitizePopulate(array $ctx, mixed $populate): mixed
    {
        self::assertSchema($ctx, 'defaultSanitizePopulate');

        return Async::pipe(
            QueryPopulate::create(new ExpandWildcardPopulate(), $ctx),
            QueryPopulate::create(static function (VisitorOptions $o, VisitorUtils $u): void {
                if ($o->attribute !== null) {
                    return;
                }

                $parent = new ParentNode($o->key, $o->path, $o->schema, $o->attribute);
                $nested = ['schema' => $o->schema, 'getModel' => $o->getModel, 'parent' => $parent];

                if ($o->key === 'sort') {
                    $u->set($o->key, self::defaultSanitizeSort($nested, $o->value));
                }
                if ($o->key === 'filters') {
                    $u->set($o->key, self::defaultSanitizeFilters($nested, $o->value));
                }
                if ($o->key === 'fields') {
                    $u->set($o->key, self::defaultSanitizeFields($nested, $o->value));
                }
                if ($o->key === 'populate') {
                    $u->set($o->key, self::defaultSanitizePopulate($nested, $o->value));
                }
            }, $ctx),
            QueryPopulate::create(new RemovePrivate(), $ctx),
        )($populate);
    }
}
