<?php

declare(strict_types=1);

namespace Strapi\Admin\Services\Permission\PermissionsManager;

use Strapi\Permissions\Engine\Abilities\Ability;
use Strapi\Permissions\Engine\Abilities\Rule;
use Strapi\Permissions\Engine\Abilities\Subject;

/**
 * Port of server/src/services/permission/permissions-manager/permission-fields.ts
 * (`createPermissionFieldsCache(ability)` → `{ getPermissionFields, clearCache }`).
 *
 * The cache stores permission field calculations per action+subjectType combination. Results are
 * only cached when rules have no entity-specific conditions, as those must be computed per entity.
 *
 * @phpstan-type PermissionFieldsResult array{permittedFields: list<string>, hasAtLeastOneRegistered: bool, shouldIncludeAll: bool}
 */
final class PermissionFields
{
    /** @var array<string, PermissionFieldsResult> */
    private array $permissionCache = [];

    public function __construct(private readonly Ability $ability)
    {
    }

    public static function createPermissionFieldsCache(Ability $ability): self
    {
        return new self($ability);
    }

    /** @return PermissionFieldsResult */
    public function getPermissionFields(string $actionOverride, mixed $subject): array
    {
        $subjectType = Subject::detectSubjectType($subject) ?? 'all';
        $rules = $this->ability->rulesFor($actionOverride, $subjectType);

        // Check if any rule has conditions that depend on entity data
        // If so, we can't cache - must compute per entity
        $hasEntityConditions = false;
        foreach ($rules as $rule) {
            if ($rule->conditions !== null && $rule->conditions !== []) {
                $hasEntityConditions = true;
                break;
            }
        }

        // Return cached result if available and safe to use
        $cacheKey = "{$actionOverride}::{$subjectType}";
        if (!$hasEntityConditions && isset($this->permissionCache[$cacheKey])) {
            return $this->permissionCache[$cacheKey];
        }

        $hasRegisteredFields = false;
        $allFieldsAllowed = false;
        foreach ($rules as $rule) {
            if ($rule->fields === null) {
                $allFieldsAllowed = true;
            } else {
                $hasRegisteredFields = true;
            }
        }

        if ($allFieldsAllowed) {
            $result = [
                'permittedFields' => [],
                'hasAtLeastOneRegistered' => $hasRegisteredFields,
                'shouldIncludeAll' => true,
            ];
        } else {
            // Compute permission fields (expensive CASL operation)
            $permittedFields = $this->ability->permittedFieldsOf(
                $actionOverride,
                $subject,
                static fn (Rule $rule): array => $rule->fields ?? [],
            );

            $result = [
                'permittedFields' => $permittedFields,
                'hasAtLeastOneRegistered' => $hasRegisteredFields,
                'shouldIncludeAll' => $permittedFields === [] && !$hasRegisteredFields,
            ];
        }

        // Cache for reuse if no entity-specific conditions
        if (!$hasEntityConditions) {
            $this->permissionCache[$cacheKey] = $result;
        }

        return $result;
    }

    public function clearCache(): void
    {
        $this->permissionCache = [];
    }
}
