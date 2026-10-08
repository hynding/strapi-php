<?php

declare(strict_types=1);

namespace Strapi\Admin\Services\Permission\PermissionsManager;

use Strapi\Admin\Domain\User;
use Strapi\Core\Strapi;
use Strapi\Permissions\Engine\Abilities\Ability;
use Strapi\Permissions\Engine\Abilities\Subject;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\Async;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\ModelCache;
use Strapi\Utils\Sanitize\Sanitizers;
use Strapi\Utils\Sanitize\Visitors\ExpandWildcardPopulate;
use Strapi\Utils\Sanitize\Visitors\RemoveDisallowedFields;
use Strapi\Utils\Sanitize\Visitors\RemovePassword;
use Strapi\Utils\Traverse\QueryFields;
use Strapi\Utils\Traverse\QueryFilters;
use Strapi\Utils\Traverse\QueryPopulate;
use Strapi\Utils\Traverse\QuerySort;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;
use Strapi\Utils\TraverseEntity;

/**
 * Port of server/src/services/permission/permissions-manager/sanitize.ts: `{ sanitizeOutput,
 * sanitizeInput, sanitizeQuery }` built for an ability, an action and a model.
 */
final class Sanitize
{
    public const array COMPONENT_FIELDS = ['__component'];

    public const array STATIC_FIELDS = [ContentTypes::ID_ATTRIBUTE, ContentTypes::DOC_ID_ATTRIBUTE];

    private readonly Schema|null $schema;

    /** @var array{schema: Schema|null, getModel: \Closure(string): (Schema|array<string, mixed>|null)} */
    private readonly array $ctx;

    /** @var \Closure(mixed, array<string, mixed>=): mixed */
    public readonly \Closure $sanitizeOutput;

    /** @var \Closure(mixed, array<string, mixed>=): mixed */
    public readonly \Closure $sanitizeInput;

    /** @var \Closure(mixed, array<string, mixed>=): mixed */
    public readonly \Closure $sanitizeQuery;

    public function __construct(
        private readonly Strapi $strapi,
        private readonly Ability $ability,
        private readonly ?string $action,
        private readonly ?string $model,
    ) {
        $this->schema = $model !== null ? $strapi->getModel($model) : null;

        // Create request-scoped model cache to avoid redundant getModel() calls
        $modelCache = ModelCache::createModelCache($strapi->getModel(...));

        $this->ctx = [
            'schema' => $this->schema,
            'getModel' => $modelCache->getModel(...),
        ];

        $this->sanitizeOutput = $this->wrapSanitize($this->createSanitizeOutput(...));
        $this->sanitizeInput = $this->wrapSanitize($this->createSanitizeInput(...));
        $this->sanitizeQuery = $this->wrapSanitize($this->createSanitizeQuery(...));
    }

    /** @param array<string, mixed> $options */
    public function sanitizeOutput(mixed $data, array $options = []): mixed
    {
        return ($this->sanitizeOutput)($data, $options);
    }

    /** @param array<string, mixed> $options */
    public function sanitizeInput(mixed $data, array $options = []): mixed
    {
        return ($this->sanitizeInput)($data, $options);
    }

    /** @param array<string, mixed> $options */
    public function sanitizeQuery(mixed $data, array $options = []): mixed
    {
        return ($this->sanitizeQuery)($data, $options);
    }

    /**
     * @param array<string, mixed> $traversalCtx `{ schema, getModel }`
     * @param callable(VisitorOptions, VisitorUtils): void ...$visitors
     * @return callable(mixed): mixed
     */
    private static function pipeTraversal(string $traversal, array $traversalCtx, callable ...$visitors): callable
    {
        $schema = $traversalCtx['schema'] ?? null;
        $getModel = $traversalCtx['getModel'] ?? null;
        if (!is_callable($getModel)) {
            throw new \LogicException('Missing getModel in traversal context');
        }
        $ctx = [
            'schema' => $schema instanceof Schema || is_array($schema) ? $schema : null,
            'getModel' => static fn (string $uid): Schema|array|null => self::modelOf($getModel($uid)),
        ];

        $fns = array_map(
            static fn (callable $visitor): \Closure => match ($traversal) {
                'filters' => QueryFilters::create($visitor, $ctx),
                'sort' => QuerySort::create($visitor, $ctx),
                'fields' => QueryFields::create($visitor, $ctx),
                'populate' => QueryPopulate::create($visitor, $ctx),
                default => TraverseEntity::create($visitor, $ctx),
            },
            $visitors,
        );

        return Async::pipe(...$fns);
    }

    /**
     * @param array<string, mixed> $options
     * @return \Closure(mixed): mixed
     */
    private function createSanitizeQuery(array $options = []): \Closure
    {
        $fields = $options['fields'];

        // TODO: sanitize relations to admin users in all sanitizers
        $permittedFields = $fields['shouldIncludeAll'] ? null : $this->getQueryFields($fields['permitted']);
        $removeDisallowedFields = new RemoveDisallowedFields($permittedFields);
        $removePassword = new RemovePassword();

        $createSanitizeFilters = fn (array $ctx): callable => self::pipeTraversal(
            'filters',
            $ctx,
            $removeDisallowedFields,
            $this->omitDisallowedAdminUserFields(...),
            $this->omitHiddenFields(...),
            $removePassword,
            static function (VisitorOptions $o, VisitorUtils $u): void {
                if (is_array($o->value) && $o->value === []) {
                    $u->remove($o->key);
                }
            },
        );

        $createSanitizeSort = fn (array $ctx): callable => self::pipeTraversal(
            'sort',
            $ctx,
            $removeDisallowedFields,
            $this->omitDisallowedAdminUserFields(...),
            $this->omitHiddenFields(...),
            $removePassword,
            static function (VisitorOptions $o, VisitorUtils $u): void {
                if (!ContentTypes::isScalarAttribute($o->attribute) && self::isEmpty($o->value)) {
                    $u->remove($o->key);
                }
            },
        );

        $createSanitizeFields = fn (array $ctx): callable => self::pipeTraversal(
            'fields',
            $ctx,
            $removeDisallowedFields,
            $this->omitHiddenFields(...),
            $removePassword,
        );

        $sanitizeFilters = $createSanitizeFilters($this->ctx);
        $sanitizeSort = $createSanitizeSort($this->ctx);
        $sanitizeFields = $createSanitizeFields($this->ctx);

        /** Sanitize nested filters, sort, and fields inside populate. */
        $sanitizeNestedPopulate = static function (VisitorOptions $o, VisitorUtils $u) use ($createSanitizeSort, $createSanitizeFilters, $createSanitizeFields): void {
            if ($o->attribute !== null) {
                return;
            }

            $nestedCtx = ['schema' => $o->schema, 'getModel' => $o->getModel];

            if ($o->key === 'sort') {
                $u->set($o->key, $createSanitizeSort($nestedCtx)($o->value));
            }

            if ($o->key === 'filters') {
                $u->set($o->key, $createSanitizeFilters($nestedCtx)($o->value));
            }

            if ($o->key === 'fields') {
                $u->set($o->key, $createSanitizeFields($nestedCtx)($o->value));
            }
        };

        $sanitizePopulate = self::pipeTraversal(
            'populate',
            $this->ctx,
            new ExpandWildcardPopulate(),
            $removeDisallowedFields,
            $this->omitDisallowedAdminUserFields(...),
            $this->omitHiddenFields(...),
            $removePassword,
            $sanitizeNestedPopulate,
        );

        return static function (mixed $query) use ($sanitizeFilters, $sanitizeSort, $sanitizePopulate, $sanitizeFields): mixed {
            if (!is_array($query)) {
                return $query;
            }

            $sanitizedQuery = $query;

            if (self::truthy($query['filters'] ?? null)) {
                $sanitizedQuery['filters'] = $sanitizeFilters($query['filters']);
            }
            if (self::truthy($query['sort'] ?? null)) {
                $sanitizedQuery['sort'] = $sanitizeSort($query['sort']);
            }
            if (self::truthy($query['populate'] ?? null)) {
                $sanitizedQuery['populate'] = $sanitizePopulate($query['populate']);
            }
            if (self::truthy($query['fields'] ?? null)) {
                $sanitizedQuery['fields'] = $sanitizeFields($query['fields']);
            }

            return $sanitizedQuery;
        };
    }

    /**
     * @param array<string, mixed> $options
     * @return callable(mixed): mixed
     */
    private function createSanitizeOutput(array $options = []): callable
    {
        $fields = $options['fields'];

        $permittedFields = $fields['shouldIncludeAll'] ? null : $this->getOutputFields($fields['permitted']);

        return Async::pipe(
            // Remove fields hidden from the admin
            TraverseEntity::create($this->omitHiddenFields(...), $this->ctx),
            // Remove unallowed fields from admin::user relations
            TraverseEntity::create($this->pickAllowedAdminUserFields(...), $this->ctx),
            // Remove not allowed fields (RBAC)
            TraverseEntity::create(new RemoveDisallowedFields($permittedFields), $this->ctx),
            // Remove all fields of type 'password'
            Sanitizers::sanitizePasswords([
                'schema' => $this->schema,
                'getModel' => $this->strapi->getModel(...),
            ]),
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return callable(mixed): mixed
     */
    private function createSanitizeInput(array $options = []): callable
    {
        $fields = $options['fields'];

        $permittedFields = $fields['shouldIncludeAll'] ? null : $this->getInputFields($fields['permitted']);

        return Async::pipe(
            // Remove fields hidden from the admin
            TraverseEntity::create($this->omitHiddenFields(...), $this->ctx),
            // Remove not allowed fields (RBAC)
            TraverseEntity::create(new RemoveDisallowedFields($permittedFields), $this->ctx),
            // Remove roles from createdBy & updatedBy fields
            self::omitCreatorRoles(...),
        );
    }

    /**
     * @param callable(array<string, mixed>): callable $createSanitizeFunction
     * @return \Closure(mixed, array<string, mixed>=): mixed
     */
    private function wrapSanitize(callable $createSanitizeFunction): \Closure
    {
        $permissionFields = PermissionFields::createPermissionFieldsCache($this->ability);

        $wrappedSanitize = function (mixed $data, array $options = []) use (&$wrappedSanitize, $permissionFields, $createSanitizeFunction): mixed {
            if (is_array($data) && $data !== [] && array_is_list($data)) {
                return array_map(static fn (mixed $entity): mixed => $wrappedSanitize($entity, $options), $data);
            }

            ['subject' => $subject, 'action' => $actionOverride] = $this->getDefaultOptions($data, $options);

            $result = $permissionFields->getPermissionFields((string) $actionOverride, $subject);

            $sanitizeOptions = [
                ...$options,
                'fields' => [
                    'shouldIncludeAll' => $result['shouldIncludeAll'],
                    'permitted' => $result['permittedFields'],
                    'hasAtLeastOneRegistered' => $result['hasAtLeastOneRegistered'],
                ],
            ];

            $sanitizeFunction = $createSanitizeFunction($sanitizeOptions);

            return $sanitizeFunction($data);
        };

        return $wrappedSanitize;
    }

    /**
     * `defaults({ subject: asSubject(model, data), action }, options)`
     *
     * @param array<string, mixed> $options
     * @return array{subject: mixed, action: mixed}
     */
    private function getDefaultOptions(mixed $data, array $options): array
    {
        $subject = $options['subject'] ?? null;
        if ($subject === null) {
            $subject = is_array($data) || is_object($data) ? Subject::subject((string) $this->model, $data) : $this->model;
        }

        return ['subject' => $subject, 'action' => $options['action'] ?? $this->action];
    }

    /** @return Schema|array<string, mixed>|null */
    private static function modelOf(mixed $model): Schema|array|null
    {
        return $model instanceof Schema || is_array($model) ? $model : null;
    }

    /** Omit creator fields' (createdBy & updatedBy) roles from the admin API responses. */
    public static function omitCreatorRoles(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        foreach ([ContentTypes::CREATED_BY_ATTRIBUTE, ContentTypes::UPDATED_BY_ATTRIBUTE] as $creatorField) {
            if (isset($data[$creatorField]) && is_array($data[$creatorField])) {
                unset($data[$creatorField]['roles']);
            }
        }

        return $data;
    }

    /** @param Schema|array<string, mixed>|null $schema */
    public static function isHiddenAttribute(Schema|array|null $schema, string $key): bool
    {
        $config = $schema instanceof Schema ? $schema->config : ($schema['config'] ?? []);
        $attributes = is_array($config) ? ($config['attributes'] ?? []) : [];
        $hidden = is_array($attributes) && is_array($attributes[$key] ?? null) ? ($attributes[$key]['hidden'] ?? false) : false;

        return !in_array($hidden, [false, null, 0, ''], true);
    }

    /** Visitor used to remove hidden fields from the admin API responses. */
    private function omitHiddenFields(VisitorOptions $o, VisitorUtils $u): void
    {
        if (self::isHiddenAttribute($o->schema, $o->key)) {
            $u->remove($o->key);
        }
    }

    /** Visitor used to only select needed fields from the admin users entities & avoid leaking sensitive information. */
    private function pickAllowedAdminUserFields(VisitorOptions $o, VisitorUtils $u): void
    {
        $attribute = $o->attribute;
        if ($attribute === null) {
            return;
        }

        $pick = static function (mixed $value): mixed {
            if (!is_array($value)) {
                return [];
            }
            $out = [];
            foreach (User::ADMIN_USER_ALLOWED_FIELDS as $field) {
                if (array_key_exists($field, $value)) {
                    $out[$field] = $value[$field];
                }
            }

            return $out;
        };

        if (($attribute['type'] ?? null) === 'relation' && ($attribute['target'] ?? null) === 'admin::user' && self::truthy($o->value)) {
            if (is_array($o->value) && array_is_list($o->value)) {
                $u->set($o->key, array_map($pick, $o->value));
            } else {
                $u->set($o->key, $pick($o->value));
            }
        }
    }

    /** Visitor used to omit disallowed fields from the admin users entities & avoid leaking sensitive information. */
    private function omitDisallowedAdminUserFields(VisitorOptions $o, VisitorUtils $u): void
    {
        if (ContentTypes::uid($o->schema) === 'admin::user' && $o->attribute !== null && !in_array($o->key, User::ADMIN_USER_ALLOWED_FIELDS, true)) {
            $u->remove($o->key);
        }
    }

    /**
     * @param list<string> $fields
     * @return list<string>
     */
    private function getInputFields(array $fields = []): array
    {
        $nonVisibleAttributes = ContentTypes::getNonVisibleAttributes($this->schema ?? []);
        $writableAttributes = ContentTypes::getWritableAttributes($this->schema);

        $nonVisibleWritableAttributes = array_values(array_intersect($nonVisibleAttributes, $writableAttributes));

        return array_values(array_unique([...$fields, ...self::COMPONENT_FIELDS, ...$nonVisibleWritableAttributes]));
    }

    /**
     * @param list<string> $fields
     * @return list<string>
     */
    private function getOutputFields(array $fields = []): array
    {
        $nonWritableAttributes = ContentTypes::getNonWritableAttributes($this->schema);
        $nonVisibleAttributes = ContentTypes::getNonVisibleAttributes($this->schema ?? []);

        return array_values(array_unique([
            ...$fields,
            ...self::STATIC_FIELDS,
            ...self::COMPONENT_FIELDS,
            ...$nonWritableAttributes,
            ...$nonVisibleAttributes,
            ContentTypes::CREATED_AT_ATTRIBUTE,
            ContentTypes::UPDATED_AT_ATTRIBUTE,
        ]));
    }

    /**
     * @param list<string> $fields
     * @return list<string>
     */
    private function getQueryFields(array $fields = []): array
    {
        $nonVisibleAttributes = ContentTypes::getNonVisibleAttributes($this->schema ?? []);
        $writableAttributes = ContentTypes::getWritableAttributes($this->schema);

        $nonVisibleWritableAttributes = array_values(array_intersect($nonVisibleAttributes, $writableAttributes));

        return array_values(array_unique([
            ...$fields,
            ...self::STATIC_FIELDS,
            ...self::COMPONENT_FIELDS,
            ...$nonVisibleWritableAttributes,
            ContentTypes::CREATED_AT_ATTRIBUTE,
            ContentTypes::UPDATED_AT_ATTRIBUTE,
            ContentTypes::PUBLISHED_AT_ATTRIBUTE,
            ContentTypes::CREATED_BY_ATTRIBUTE,
            ContentTypes::UPDATED_BY_ATTRIBUTE,
        ]));
    }

    /** JS truthiness (`[]` is a truthy object). */
    public static function truthy(mixed $value): bool
    {
        return !($value === null || $value === false || $value === 0 || $value === 0.0 || $value === '');
    }

    /** lodash `isEmpty`. */
    public static function isEmpty(mixed $value): bool
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return true;
        }
        if (is_string($value)) {
            return $value === '';
        }
        if (is_array($value)) {
            return $value === [];
        }
        if (is_object($value)) {
            return get_object_vars($value) === [];
        }

        return true;
    }
}
