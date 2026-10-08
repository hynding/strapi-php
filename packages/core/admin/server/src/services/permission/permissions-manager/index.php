<?php

declare(strict_types=1);

namespace Strapi\Admin\Services\Permission\PermissionsManager;

use Strapi\Core\Strapi;
use Strapi\Permissions\Engine\Abilities\Ability;
use Strapi\Permissions\Engine\Abilities\Subject;

/**
 * Port of server/src/services/permission/permissions-manager/index.ts
 * (`createPermissionsManager({ ability, action, model })`).
 *
 * Upstream spreads the sanitize and validate helpers into the object; here they are methods
 * forwarding to {@see Sanitize} and {@see Validate}. `isAllowed` is a method.
 */
final class PermissionsManager
{
    private readonly Sanitize $sanitize;

    private readonly Validate $validate;

    public function __construct(
        Strapi $strapi,
        public readonly Ability $ability,
        public readonly ?string $action = null,
        public readonly ?string $model = null,
    ) {
        $this->sanitize = new Sanitize($strapi, $ability, $action, $model);
        $this->validate = new Validate($strapi, $ability, $action, $model);
    }

    /**
     * @param array{ability: Ability, action?: string|null, model?: string|null} $params
     */
    public static function createPermissionsManager(Strapi $strapi, array $params): self
    {
        return new self($strapi, $params['ability'], $params['action'] ?? null, $params['model'] ?? null);
    }

    /** upstream getter `isAllowed` */
    public function isAllowed(): bool
    {
        return $this->ability->can((string) $this->action, $this->model ?? 'all');
    }

    /**
     * @param array<string, mixed>|object $target
     * @return array<string, mixed>|object
     */
    public function toSubject(array|object $target, ?string $subjectType = null): array|object
    {
        return Subject::subject($subjectType ?? (string) $this->model, $target);
    }

    /** @param array<string, mixed> $options */
    public function pickPermittedFieldsOf(mixed $data, array $options = []): mixed
    {
        return $this->sanitizeInput($data, $options);
    }

    /** @return array<string, mixed>|null */
    public function getQuery(?string $queryAction = null): mixed
    {
        $queryAction ??= $this->action;

        if ($queryAction === null) {
            throw new \RuntimeException('Action must be defined to build a permission query');
        }

        return QueryBuilders::buildStrapiQuery(QueryBuilders::buildCaslQuery($this->ability, $queryAction, $this->model));
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function addPermissionsQueryTo(array $query = [], ?string $action = null): array
    {
        $newQuery = $query;
        // `null` (nothing allowed) becomes `undefined`; `[]` (everything allowed) is an empty object
        $permissionQuery = $this->getQuery($action);

        $filters = $query['filters'] ?? null;
        if (is_array($filters) && ($filters === [] || !array_is_list($filters))) {
            $newQuery['filters'] = $permissionQuery !== null ? ['$and' => [$filters, $permissionQuery]] : $filters;
        } elseif ($permissionQuery === null) {
            unset($newQuery['filters']);
        } else {
            $newQuery['filters'] = $permissionQuery;
        }

        return $newQuery;
    }

    /** @param array<string, mixed> $options */
    public function sanitizeOutput(mixed $data, array $options = []): mixed
    {
        return $this->sanitize->sanitizeOutput($data, $options);
    }

    /** @param array<string, mixed> $options */
    public function sanitizeInput(mixed $data, array $options = []): mixed
    {
        return $this->sanitize->sanitizeInput($data, $options);
    }

    /** @param array<string, mixed> $options */
    public function sanitizeQuery(mixed $data, array $options = []): mixed
    {
        return $this->sanitize->sanitizeQuery($data, $options);
    }

    /** @param array<string, mixed> $options */
    public function validateQuery(mixed $data, array $options = []): mixed
    {
        return $this->validate->validateQuery($data, $options);
    }

    /** @param array<string, mixed> $options */
    public function validateInput(mixed $data, array $options = []): mixed
    {
        return $this->validate->validateInput($data, $options);
    }
}
