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
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\ModelCache;
use Strapi\Utils\Traverse\QueryFields;
use Strapi\Utils\Traverse\QueryFilters;
use Strapi\Utils\Traverse\QueryPopulate;
use Strapi\Utils\Traverse\QuerySort;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;
use Strapi\Utils\TraverseEntity;
use Strapi\Utils\Validate\Visitors\ThrowDisallowedFields;
use Strapi\Utils\Validate\Visitors\ThrowPassword;

/**
 * Port of server/src/services/permission/permissions-manager/validate.ts: `{ validateQuery,
 * validateInput }` built for an ability, an action and a model.
 */
final class Validate
{
    public const array COMPONENT_FIELDS = ['__component'];

    public const array STATIC_FIELDS = [ContentTypes::ID_ATTRIBUTE, ContentTypes::DOC_ID_ATTRIBUTE];

    private readonly Schema|null $schema;

    /** @var array{schema: Schema|null, getModel: \Closure(string): (Schema|array<string, mixed>|null)} */
    private readonly array $ctx;

    /** @var \Closure(mixed, array<string, mixed>=): mixed */
    public readonly \Closure $validateQuery;

    /** @var \Closure(mixed, array<string, mixed>=): mixed */
    public readonly \Closure $validateInput;

    public function __construct(
        Strapi $strapi,
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

        $this->validateQuery = $this->wrapValidate($this->createValidateQuery(...));
        $this->validateInput = $this->wrapValidate($this->createValidateInput(...));
    }

    /** @param array<string, mixed> $options */
    public function validateQuery(mixed $data, array $options = []): mixed
    {
        return ($this->validateQuery)($data, $options);
    }

    /** @param array<string, mixed> $options */
    public function validateInput(mixed $data, array $options = []): mixed
    {
        return ($this->validateInput)($data, $options);
    }

    /** @return Schema|array<string, mixed>|null */
    private static function modelOf(mixed $model): Schema|array|null
    {
        return $model instanceof Schema || is_array($model) ? $model : null;
    }

    /** @throws ValidationError */
    public static function throwInvalidKey(string $key, ?string $path = null): never
    {
        $msg = $path !== null && $path !== '' && $path !== $key ? "Invalid key {$key} at {$path}" : "Invalid key {$key}";

        throw new ValidationError($msg);
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
    private function createValidateQuery(array $options = []): \Closure
    {
        $fields = $options['fields'];

        // TODO: validate relations to admin users in all validators
        $permittedFields = $fields['shouldIncludeAll'] ? null : $this->getQueryFields($fields['permitted']);
        $throwDisallowedFields = new ThrowDisallowedFields($permittedFields);
        $throwPassword = new ThrowPassword();

        $createValidateFilters = fn (array $ctx): callable => self::pipeTraversal(
            'filters',
            $ctx,
            $throwDisallowedFields,
            $this->throwDisallowedAdminUserFields(...),
            $throwPassword,
            static function (VisitorOptions $o): void {
                if (is_array($o->value) && $o->value === []) {
                    self::throwInvalidKey($o->key, $o->path->attribute);
                }
            },
        );

        $createValidateSort = fn (array $ctx): callable => self::pipeTraversal(
            'sort',
            $ctx,
            $throwDisallowedFields,
            $this->throwDisallowedAdminUserFields(...),
            $throwPassword,
            static function (VisitorOptions $o): void {
                if (!ContentTypes::isScalarAttribute($o->attribute) && Sanitize::isEmpty($o->value)) {
                    self::throwInvalidKey($o->key, $o->path->attribute);
                }
            },
        );

        $createValidateFields = static fn (array $ctx): callable => self::pipeTraversal(
            'fields',
            $ctx,
            $throwDisallowedFields,
            $throwPassword,
        );

        $validateFilters = $createValidateFilters($this->ctx);
        $validateSort = $createValidateSort($this->ctx);
        $validateFields = $createValidateFields($this->ctx);

        $validateNestedPopulate = static function (VisitorOptions $o) use ($createValidateSort, $createValidateFilters, $createValidateFields): void {
            if ($o->attribute !== null) {
                return;
            }

            $nestedCtx = ['schema' => $o->schema, 'getModel' => $o->getModel];

            if ($o->key === 'sort') {
                $createValidateSort($nestedCtx)($o->value);
            }

            if ($o->key === 'filters') {
                $createValidateFilters($nestedCtx)($o->value);
            }

            if ($o->key === 'fields') {
                $createValidateFields($nestedCtx)($o->value);
            }
        };

        $validatePopulate = self::pipeTraversal(
            'populate',
            $this->ctx,
            $throwDisallowedFields,
            $this->throwDisallowedAdminUserFields(...),
            $this->throwHiddenFields(...),
            $throwPassword,
            $validateNestedPopulate,
        );

        return static function (mixed $query) use ($validateFilters, $validateSort, $validateFields, $validatePopulate): bool {
            if (!is_array($query)) {
                return true;
            }

            if (Sanitize::truthy($query['filters'] ?? null)) {
                $validateFilters($query['filters']);
            }

            if (Sanitize::truthy($query['sort'] ?? null)) {
                $validateSort($query['sort']);
            }

            if (Sanitize::truthy($query['fields'] ?? null)) {
                $validateFields($query['fields']);
            }

            // a wildcard is always valid; its conversion will be handled by the entity service and can be optimized with sanitizer
            if (Sanitize::truthy($query['populate'] ?? null) && $query['populate'] !== '*') {
                $validatePopulate($query['populate']);
            }

            return true;
        };
    }

    /**
     * @param array<string, mixed> $options
     * @return callable(mixed): mixed
     */
    private function createValidateInput(array $options = []): callable
    {
        $fields = $options['fields'];

        $permittedFields = $fields['shouldIncludeAll'] ? null : $this->getInputFields($fields['permitted']);

        return Async::pipe(
            // Remove fields hidden from the admin
            TraverseEntity::create($this->throwHiddenFields(...), $this->ctx),
            // Remove not allowed fields (RBAC)
            TraverseEntity::create(new ThrowDisallowedFields($permittedFields), $this->ctx),
            // Remove roles from createdBy & updatedBy fields
            Sanitize::omitCreatorRoles(...),
        );
    }

    /**
     * @param callable(array<string, mixed>): callable $createValidateFunction
     * @return \Closure(mixed, array<string, mixed>=): mixed
     */
    private function wrapValidate(callable $createValidateFunction): \Closure
    {
        $permissionFields = PermissionFields::createPermissionFieldsCache($this->ability);

        $wrappedValidate = function (mixed $data, array $options = []) use (&$wrappedValidate, $permissionFields, $createValidateFunction): mixed {
            if (is_array($data) && $data !== [] && array_is_list($data)) {
                return array_map(static fn (mixed $entity): mixed => $wrappedValidate($entity, $options), $data);
            }

            $subject = $options['subject'] ?? null;
            if ($subject === null) {
                $subject = is_array($data) || is_object($data) ? Subject::subject((string) $this->model, $data) : $this->model;
            }
            $actionOverride = $options['action'] ?? $this->action;

            $result = $permissionFields->getPermissionFields((string) $actionOverride, $subject);

            $validateOptions = [
                ...$options,
                'fields' => [
                    'shouldIncludeAll' => $result['shouldIncludeAll'],
                    'permitted' => $result['permittedFields'],
                    'hasAtLeastOneRegistered' => $result['hasAtLeastOneRegistered'],
                ],
            ];

            $validateFunction = $createValidateFunction($validateOptions);

            return $validateFunction($data);
        };

        return $wrappedValidate;
    }

    /** Visitor used to remove hidden fields from the admin API responses. */
    private function throwHiddenFields(VisitorOptions $o): void
    {
        if (Sanitize::isHiddenAttribute($o->schema, $o->key)) {
            self::throwInvalidKey($o->key, $o->path->attribute);
        }
    }

    /** Visitor used to omit disallowed fields from the admin users entities & avoid leaking sensitive information. */
    private function throwDisallowedAdminUserFields(VisitorOptions $o): void
    {
        if (ContentTypes::uid($o->schema) === 'admin::user' && $o->attribute !== null && !in_array($o->key, User::ADMIN_USER_ALLOWED_FIELDS, true)) {
            self::throwInvalidKey($o->key, $o->path->attribute);
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
    private function getQueryFields(array $fields = []): array
    {
        return array_values(array_unique([
            ...$fields,
            ...self::STATIC_FIELDS,
            ...self::COMPONENT_FIELDS,
            ContentTypes::CREATED_AT_ATTRIBUTE,
            ContentTypes::UPDATED_AT_ATTRIBUTE,
            ContentTypes::PUBLISHED_AT_ATTRIBUTE,
        ]));
    }
}
