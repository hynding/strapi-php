<?php

declare(strict_types=1);

namespace Strapi\Admin\Services;

use Strapi\Admin\AuditLogs\Tokens;
use Strapi\Admin\Domain\Permission\Permission as PermissionDomain;
use Strapi\Admin\Strategies\ApiTokenUtils;
use Strapi\Admin\Utils\Utils;
use Strapi\Admin\Validation\Permission as PermissionValidation;
use Strapi\Core\Strapi;
use Strapi\Permissions\Engine\Abilities\Ability;
use Strapi\Utils\AuditLogs;
use Strapi\Utils\Errors\NotFoundError;
use Strapi\Utils\Errors\UnauthorizedError;
use Strapi\Utils\Errors\ValidationError;

/**
 * Port of server/src/services/api-token.ts.
 *
 * Upstream's `createTokenService(kind)` returns one service object per token kind
 * (`admin::api-token-content-api`, its deprecated alias `admin::api-token`, and
 * `admin::api-token-admin`) built over the module's functions. Here one class carries the
 * module's functions as public methods and the `kind` it was created for: the kind-bound
 * methods (`create`, `list`, `getById`, `getByName`) apply the kind like upstream's wrappers.
 *
 * Tokens are plain arrays; a JS `undefined` property is an absent key.
 *
 * @phpstan-type ApiTokenRow array<string, mixed>
 * @phpstan-type PermissionInput array<string, mixed>
 */
final class ApiToken
{
    private const SELECT_FIELDS = [
        'id',
        'kind',
        'name',
        'description',
        'lastUsedAt',
        'type',
        'lifespan',
        'expiresAt',
        'createdAt',
        'updatedAt',
    ];

    private const POPULATE_FIELDS = ['permissions', 'adminPermissions', 'adminUserOwner'];

    private const UPDATABLE_FIELDS = ['name', 'description', 'type'];

    /** @param 'content-api'|'admin' $kind */
    public function __construct(private readonly Strapi $strapi, public readonly string $kind)
    {
    }

    /** @param 'content-api'|'admin' $kind */
    public static function createTokenService(Strapi $strapi, string $kind): self
    {
        return new self($strapi, $kind);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $object
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function omit(array $object, array $keys): array
    {
        return array_diff_key($object, array_flip($keys));
    }

    /** @param array<string, mixed>|null $callingUser */
    private function assertOwnerMatchesCallingUser(mixed $adminUserOwner, ?array $callingUser): void
    {
        if ($callingUser === null) {
            throw new ValidationError('adminUserOwner requires an authenticated admin user');
        }

        $ownerId = self::idString($adminUserOwner);
        $callingUserId = self::idString($callingUser['id'] ?? null);

        if ($ownerId !== $callingUserId) {
            throw new ValidationError('adminUserOwner must match the authenticated admin user');
        }

        $existingUser = $this->strapi->db()->query('admin::user')->findOne([
            'select' => ['id'],
            'where' => ['id' => $callingUser['id'] ?? null],
        ]);

        if ($existingUser === null) {
            throw new ValidationError('adminUserOwner must reference an existing admin user');
        }
    }

    /** `String(value)` for an id. */
    private static function idString(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }

    /** @param array<string, mixed>|null $user */
    private static function isSuperAdmin(?array $user): bool
    {
        foreach (is_array($user['roles'] ?? null) ? $user['roles'] : [] as $role) {
            if (is_array($role) && ($role['code'] ?? null) === Constants::SUPER_ADMIN_CODE) {
                return true;
            }
        }

        return false;
    }

    /** @param ApiTokenRow $token */
    private static function getOwnerId(array $token): string
    {
        $owner = $token['adminUserOwner'] ?? null;

        return self::idString(is_array($owner) ? ($owner['id'] ?? null) : $owner);
    }

    /** @param ApiTokenRow $token */
    private static function resolveAdminTokenOwnerId(array $token): mixed
    {
        $owner = $token['adminUserOwner'] ?? null;

        if ($owner === null) {
            return null;
        }

        if (is_array($owner)) {
            return $owner['id'] ?? null;
        }

        return $owner;
    }

    private static function toAdminTokenOwner(mixed $owner): mixed
    {
        if ($owner === null) {
            throw new \RuntimeException('adminUserOwner is required');
        }

        // Bare id
        if (!is_array($owner)) {
            return $owner;
        }

        return [
            'id' => $owner['id'] ?? null,
            'firstname' => $owner['firstname'] ?? null,
            'lastname' => $owner['lastname'] ?? null,
            'username' => $owner['username'] ?? null,
            'email' => $owner['email'] ?? null,
        ];
    }

    /** lodash `isEmpty`. */
    private static function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_array($value)) {
            return $value === [];
        }
        if (is_string($value)) {
            return $value === '';
        }

        // numbers and booleans are "empty" for lodash
        return true;
    }

    /**
     * Assert that a token's permissions attribute is valid for its type
     */
    private function assertCustomTokenPermissionsValidity(mixed $type, mixed $permissions): void
    {
        // Ensure non-custom tokens doesn't have permissions
        if ($type !== Constants::API_TOKEN_TYPE['CUSTOM'] && !self::isEmpty($permissions)) {
            throw new ValidationError('Non-custom tokens should not reference permissions');
        }

        // Custom type tokens should always have permissions attached to them
        if ($type === Constants::API_TOKEN_TYPE['CUSTOM'] && !(is_array($permissions) && array_is_list($permissions))) {
            throw new ValidationError('Missing permissions attribute for custom token');
        }

        // Permissions provided for a custom type token should be valid/registered permissions UID
        if ($type === Constants::API_TOKEN_TYPE['CUSTOM']) {
            $validPermissions = $this->strapi->contentAPI()->permissions->providers['action']->keys();
            /** @var list<mixed> $permissions */
            $invalidPermissions = array_values(array_filter(
                $permissions,
                static fn (mixed $permission): bool => !in_array($permission, $validPermissions, true),
            ));

            if ($invalidPermissions !== []) {
                throw new ValidationError('Unknown permissions provided: ' . implode(', ', array_map(self::idString(...), $invalidPermissions)));
            }
        }
    }

    /** `isNumber(value)` with `Object.values(constants.API_TOKEN_LIFESPANS).includes(value)` */
    private static function isValidLifespan(mixed $lifespan): bool
    {
        if ($lifespan === null) {
            return true;
        }

        if (!is_int($lifespan) && !is_float($lifespan)) {
            return false;
        }

        foreach (Constants::API_TOKEN_LIFESPANS as $allowed) {
            if ($allowed !== null && $allowed == $lifespan) {
                return true;
            }
        }

        return false;
    }

    private static function assertValidLifespan(mixed $lifespan): void
    {
        if (!self::isValidLifespan($lifespan)) {
            $values = implode(', ', array_map(static fn (mixed $v): string => $v === null ? '' : (string) $v, array_values(Constants::API_TOKEN_LIFESPANS)));
            throw new ValidationError("lifespan must be one of the following values:\n      {$values}");
        }
    }

    /**
     * Assert that a legacy-kind token body does not carry admin-only fields
     *
     * @param array<string, mixed> $attributes
     */
    private static function assertLegacyKindFields(array $attributes): void
    {
        if (($attributes['adminPermissions'] ?? null) !== null) {
            throw new ValidationError('Legacy tokens cannot carry admin permissions');
        }
        if (($attributes['adminUserOwner'] ?? null) !== null) {
            throw new ValidationError('Legacy tokens cannot have an admin user owner');
        }
    }

    /**
     * Assert that an admin-kind token body does not carry legacy-only fields
     *
     * @param array<string, mixed> $attributes
     */
    private static function assertAdminKindFields(array $attributes): void
    {
        if (($attributes['type'] ?? null) !== null) {
            throw new ValidationError('Admin tokens cannot carry a legacy type (custom/read-only/full-access)');
        }
        if (($attributes['permissions'] ?? null) !== null) {
            throw new ValidationError('Admin tokens cannot carry legacy content-API permissions');
        }
    }

    private function permissionService(): Permission
    {
        return Utils::getService($this->strapi, 'permission');
    }

    /**
     * Assert that admin permissions are valid
     *
     * @param list<PermissionInput>|null $adminPermissions
     */
    private function assertAdminPermissionsValidity(?array $adminPermissions): void
    {
        if ($adminPermissions === null || $adminPermissions === []) {
            return;
        }

        // Validate that all actions exist in the admin action provider
        $validActions = $this->permissionService()->actionProvider->keys();

        foreach ($adminPermissions as $perm) {
            $action = is_array($perm) ? ($perm['action'] ?? null) : null;
            if (!in_array($action, $validActions, true)) {
                throw new ValidationError('Unknown admin action: ' . self::idString($action));
            }
        }

        // Use existing permission validation
        PermissionValidation::validatePermissionsExist($adminPermissions);
    }

    /**
     * `{ ...perm, conditions: sanitized.conditions }` where `sanitized` is the permission with its
     * unregistered conditions removed.
     *
     * @param PermissionInput $perm
     * @return PermissionInput
     */
    private function withSanitizedConditions(array $perm): array
    {
        $sanitized = PermissionDomain::sanitizeConditions($this->permissionService()->conditionProvider, [...$perm, 'actionParameters' => []]);

        $result = $perm;
        unset($result['conditions']);
        if (array_key_exists('conditions', $sanitized)) {
            $result['conditions'] = $sanitized['conditions'];
        }

        return $result;
    }

    /**
     * `p.properties?.fields`, or null when undefined/null.
     *
     * @param array<string, mixed> $permission
     */
    private static function fieldsOf(array $permission): mixed
    {
        $properties = $permission['properties'] ?? null;

        return is_array($properties) ? ($properties['fields'] ?? null) : null;
    }

    /**
     * `!p.properties?.fields || p.properties.fields.length === 0`
     *
     * @param array<string, mixed> $permission
     */
    private static function hasAllFields(array $permission): bool
    {
        $fields = self::fieldsOf($permission);

        return $fields === null || $fields === false || $fields === '' || $fields === 0 || (is_array($fields) && $fields === []);
    }

    /** @param array<string, mixed> $permission */
    private static function isUnconditional(array $permission): bool
    {
        $conditions = $permission['conditions'] ?? null;

        return !is_array($conditions) || $conditions === [];
    }

    /**
     * `x || null` for a subject
     *
     * @param array<string, mixed> $permission
     */
    private static function subjectOf(array $permission): mixed
    {
        $subject = $permission['subject'] ?? null;

        return $subject === '' || $subject === false || $subject === 0 ? null : $subject;
    }

    /**
     * Union of a list of lists, keeping first-seen order (lodash `uniq(flatMap(...))`).
     *
     * @param list<array<string, mixed>> $permissions
     * @return list<mixed>
     */
    private static function unionOf(array $permissions, \Closure $pluck): array
    {
        $out = [];
        foreach ($permissions as $permission) {
            $values = $pluck($permission);
            foreach (is_array($values) ? $values : [] as $value) {
                if (!in_array($value, $out, true)) {
                    $out[] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * Enforce that every requested admin permission stays within the calling
     * user's own permission ceiling, then return the clamped permissions.
     *
     * Super-admins bypass this (they hold every permission).
     * When admin permissions are requested, an authenticated user is required (no bypass when user is missing).
     *
     * For each requested permission:
     *  - action + subject must match at least one user permission
     *  - properties.fields must be ⊆ user's properties.fields
     *    (if the user's permission defines no fields, all fields are allowed)
     *  - conditions are inherited from the user's matching permission(s);
     *    the caller cannot configure conditions on their own tokens
     *
     * Returns the permissions with conditions enforced from the user's role.
     * Throws ValidationError if any permission exceeds the user's ceiling.
     *
     * Guaranteed postcondition: all returned permissions have conditions filtered to
     * registered conditions only, regardless of the user type.
     *
     * @param array<string, mixed>|null $user
     * @param list<PermissionInput>|null $requestedPermissions
     * @return list<PermissionInput>
     */
    public function enforceAdminPermissionsCeiling(?array $user, ?array $requestedPermissions): array
    {
        if ($requestedPermissions === null || $requestedPermissions === []) {
            return $requestedPermissions ?? [];
        }
        if ($user === null) {
            throw new ValidationError('Admin permission ceiling cannot be enforced without an authenticated user');
        }
        if (self::isSuperAdmin($user)) {
            // Sanitize conditions even for super-admins so this function is a complete boundary.
            // createApiTokenAdminPermissions also sanitizes, but relying on that downstream
            // is fragile — any future call path that skips it would store invalid conditions.
            return array_map($this->withSanitizedConditions(...), array_values($requestedPermissions));
        }

        $userPermissions = $this->permissionService()->findUserPermissions($user);

        $exceeding = [];

        $clamped = array_map(static function (array $requested) use ($userPermissions, &$exceeding): array {
            $requestedSubject = self::subjectOf($requested);
            $requestedAction = $requested['action'] ?? null;

            // Find all user permissions matching action + subject
            $matchingUserPerms = array_values(array_filter(
                $userPermissions,
                static fn (array $userPerm): bool => ($userPerm['action'] ?? null) === $requestedAction
                    && self::subjectOf($userPerm) === $requestedSubject,
            ));

            $label = $requestedSubject !== null
                ? self::idString($requestedAction) . ' on ' . self::idString($requestedSubject)
                : self::idString($requestedAction);

            if ($matchingUserPerms === []) {
                $exceeding[] = $label;

                return $requested;
            }

            // --- Field-level ceiling ---
            // If any matching user perm has no fields defined → all fields are allowed.
            // Otherwise, effective user fields = union of all matching perms' fields.
            $anyUserPermHasAllFields = false;
            foreach ($matchingUserPerms as $p) {
                if (self::hasAllFields($p)) {
                    $anyUserPermHasAllFields = true;
                    break;
                }
            }

            $requestedFields = self::fieldsOf($requested);

            if (!$anyUserPermHasAllFields) {
                $effectiveUserFields = self::unionOf($matchingUserPerms, static fn (array $p): mixed => self::fieldsOf($p));

                // When the owner is field-restricted, omitting fields would widen access to all fields.
                // Force explicit field selection so token scope can't exceed the owner's ceiling.
                if ($requestedFields === null) {
                    $exceeding[] = "{$label} (fields are required due to owner field restrictions)";

                    return $requested;
                }

                if (is_array($requestedFields) && $requestedFields !== []) {
                    $exceedingFields = array_values(array_filter(
                        $requestedFields,
                        static fn (mixed $f): bool => !in_array($f, $effectiveUserFields, true),
                    ));

                    if ($exceedingFields !== []) {
                        $exceeding[] = "{$label} (fields: " . implode(', ', array_map(self::idString(...), $exceedingFields)) . ')';

                        return $requested;
                    }
                }
            }

            // --- Condition-level ceiling ---
            // Conditions are always inherited from the user's matching permission(s).
            // If any matching user perm is unconditional → token gets no conditions.
            // Otherwise → union of conditions across matching perms.
            $anyUserPermIsUnconditional = false;
            foreach ($matchingUserPerms as $p) {
                if (self::isUnconditional($p)) {
                    $anyUserPermIsUnconditional = true;
                    break;
                }
            }

            $enforcedConditions = $anyUserPermIsUnconditional
                ? []
                : self::unionOf($matchingUserPerms, static fn (array $p): mixed => $p['conditions'] ?? []);

            return [
                ...$requested,
                'conditions' => $enforcedConditions,
            ];
        }, array_values($requestedPermissions));

        if ($exceeding !== []) {
            throw new ValidationError('Cannot assign admin permissions that exceed your own. Exceeding: ' . implode(', ', $exceeding));
        }

        return $clamped;
    }

    /**
     * Create admin permissions for an API token
     *
     * @param list<PermissionInput> $permissions
     * @return list<array<string, mixed>>
     */
    private function createApiTokenAdminPermissions(mixed $tokenId, array $permissions): array
    {
        $conditionProvider = $this->permissionService()->conditionProvider;

        $permissionsWithToken = array_map(static function (array $perm) use ($conditionProvider, $tokenId): array {
            $sanitized = PermissionDomain::sanitizeConditions($conditionProvider, [...$perm, 'actionParameters' => []]);

            return PermissionDomain::create([
                ...$sanitized,
                'apiToken' => $tokenId,
                'role' => null,
            ]);
        }, array_values($permissions));

        return $this->permissionService()->createMany($permissionsWithToken);
    }

    /**
     * lodash/fp `differenceWith(arePermissionsEqual, $values, $others)`.
     *
     * @param list<array<string, mixed>> $values
     * @param list<array<string, mixed>> $others
     * @return list<array<string, mixed>>
     */
    private static function differenceWith(array $values, array $others): array
    {
        return array_values(array_filter($values, static function (array $value) use ($others): bool {
            foreach ($others as $other) {
                if (Role::arePermissionsEqual($value, $other)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Assign admin permissions to an API token (similar to role permission assignment).
     * ceilingUser is the user whose permissions act as the ceiling — always the token owner,
     * regardless of who is making the request.
     *
     * @param list<PermissionInput> $permissions
     * @param array<string, mixed> $ceilingUser
     * @return list<array<string, mixed>>
     */
    public function assignAdminPermissionsToToken(mixed $tokenId, array $permissions, array $ceilingUser): array
    {
        PermissionValidation::validatePermissionsExist($permissions);
        $clampedPermissions = $this->enforceAdminPermissionsCeiling($ceilingUser, $permissions);

        $permissionsWithToken = array_map(
            static fn (array $perm): array => PermissionDomain::create([
                ...$perm,
                'apiToken' => $tokenId,
                'role' => null,
            ]),
            $clampedPermissions,
        );

        $existingPermissions = $this->permissionService()->findMany([
            'where' => ['apiToken' => ['id' => $tokenId]],
        ]);

        $permissionsToAdd = self::differenceWith($permissionsWithToken, $existingPermissions);
        $permissionsToDelete = self::differenceWith($existingPermissions, $permissionsWithToken);

        if ($permissionsToDelete !== []) {
            $this->permissionService()->deleteByIds(array_map(static fn (array $p): mixed => $p['id'] ?? null, $permissionsToDelete));
        }

        if ($permissionsToAdd !== []) {
            $this->createApiTokenAdminPermissions($tokenId, $permissionsToAdd);
        }

        // Return all current permissions
        return $this->permissionService()->findMany([
            'where' => ['apiToken' => ['id' => $tokenId]],
        ]);
    }

    /**
     * Reconcile a token's admin permissions against the owner's current effective ceiling.
     *
     * Pure / sync — no DB calls. Returns two buckets:
     *   toDelete  – permissions that are no longer within the user's scope (action/subject missing
     *               or requested fields exceed the allowed set)
     *   toUpdate  – permissions that are still in scope but whose conditions must be re-clamped
     *               to the current union of the matching user permissions' conditions
     *
     * @param list<array<string, mixed>> $userPermissions
     * @param list<array<string, mixed>> $tokenPermissions
     * @return array{toDelete: list<array<string, mixed>>, toUpdate: list<array{id: mixed, conditions: list<mixed>}>}
     */
    public function reconcileTokenPermissionsToUserCeiling(array $userPermissions, array $tokenPermissions): array
    {
        $toDelete = [];
        $toUpdate = [];

        foreach ($tokenPermissions as $tokenPerm) {
            $tokenSubject = self::subjectOf($tokenPerm);

            $matchingUserPerms = array_values(array_filter(
                $userPermissions,
                static fn (array $userPerm): bool => ($userPerm['action'] ?? null) === ($tokenPerm['action'] ?? null)
                    && self::subjectOf($userPerm) === $tokenSubject,
            ));

            if ($matchingUserPerms === []) {
                $toDelete[] = $tokenPerm;
                continue;
            }

            // Field-level ceiling check (mirrors enforceAdminPermissionsCeiling)
            $anyUserPermHasAllFields = false;
            foreach ($matchingUserPerms as $p) {
                if (self::hasAllFields($p)) {
                    $anyUserPermHasAllFields = true;
                    break;
                }
            }
            $tokenFields = self::fieldsOf($tokenPerm);

            $fieldCeilingExceeded = false;
            if (!$anyUserPermHasAllFields && is_array($tokenFields) && $tokenFields !== []) {
                $effectiveUserFields = self::unionOf($matchingUserPerms, static fn (array $p): mixed => self::fieldsOf($p));
                foreach ($tokenFields as $f) {
                    if (!in_array($f, $effectiveUserFields, true)) {
                        $fieldCeilingExceeded = true;
                        break;
                    }
                }
            }

            if ($fieldCeilingExceeded) {
                $toDelete[] = $tokenPerm;
                continue;
            }

            // Condition: force conditions to be the ones of the user permission(s)
            $anyUserPermIsUnconditional = false;
            foreach ($matchingUserPerms as $p) {
                if (self::isUnconditional($p)) {
                    $anyUserPermIsUnconditional = true;
                    break;
                }
            }
            $enforcedConditions = $anyUserPermIsUnconditional
                ? []
                : self::unionOf($matchingUserPerms, static fn (array $p): mixed => $p['conditions'] ?? []);

            $currentConditions = is_array($tokenPerm['conditions'] ?? null) ? array_values($tokenPerm['conditions']) : [];
            $conditionsChanged = count($enforcedConditions) !== count($currentConditions);
            if (!$conditionsChanged) {
                foreach ($enforcedConditions as $c) {
                    if (!in_array($c, $currentConditions, true)) {
                        $conditionsChanged = true;
                        break;
                    }
                }
            }

            if ($conditionsChanged) {
                $toUpdate[] = ['id' => $tokenPerm['id'] ?? null, 'conditions' => $enforcedConditions];
            }
        }

        return ['toDelete' => $toDelete, 'toUpdate' => $toUpdate];
    }

    /**
     * Re-sync all admin token permissions for a given user against their current effective ceiling.
     *
     * Skips super-admins (no ceiling). For each admin token owned by the user:
     *   - Deletes permissions that are no longer within the user's scope
     *   - Updates conditions on permissions whose conditions have drifted from the role's current set
     */
    public function syncApiTokenPermissionsForUser(mixed $userId): void
    {
        $user = $this->strapi->db()->query('admin::user')->findOne([
            'where' => ['id' => $userId],
            'populate' => ['roles'],
        ]);

        if ($user === null) {
            return;
        }
        if (self::isSuperAdmin($user)) {
            return;
        }

        $userEffectivePermissions = $this->permissionService()->findUserPermissions($user);

        $tokens = $this->strapi->db()->query('admin::api-token')->findMany([
            'where' => ['kind' => 'admin', 'adminUserOwner' => ['id' => $userId]],
            'populate' => ['adminPermissions'],
        ]);

        foreach ($tokens as $token) {
            $tokenPermissions = $token['adminPermissions'] ?? null;
            if (!is_array($tokenPermissions) || $tokenPermissions === []) {
                continue;
            }

            ['toDelete' => $toDelete, 'toUpdate' => $toUpdate] = $this->reconcileTokenPermissionsToUserCeiling(
                $userEffectivePermissions,
                array_values($tokenPermissions),
            );

            if ($toDelete !== []) {
                $this->permissionService()->deleteByIds(array_map(static fn (array $p): mixed => $p['id'] ?? null, $toDelete));
            }

            foreach ($toUpdate as ['id' => $id, 'conditions' => $conditions]) {
                $this->strapi->db()->query('admin::permission')->update(['where' => ['id' => $id], 'data' => ['conditions' => $conditions]]);
            }
        }
    }

    /**
     * Re-sync admin token permissions for all admin users who hold a given role.
     * Called after role permissions are updated.
     */
    public function syncApiTokenPermissionsForRole(mixed $roleId): void
    {
        $users = $this->strapi->db()->query('admin::user')->findMany([
            'where' => ['roles' => ['id' => $roleId]],
            'populate' => ['roles'],
        ]);

        // Promise.allSettled: one user's failure does not stop the others
        foreach ($users as $user) {
            try {
                $this->syncApiTokenPermissionsForUser($user['id'] ?? null);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Flatten a token's database permissions objects to an array of strings
     *
     * @return list<mixed>
     */
    private static function flattenTokenPermissions(mixed $permissions): array
    {
        return is_array($permissions)
            ? array_map(static fn (mixed $p): mixed => is_array($p) ? ($p['action'] ?? null) : null, array_values($permissions))
            : [];
    }

    /**
     *  Get a token.
     *  By default the plaintext accessKey is NOT included.
     *  Pass ['includeDecryptedKey' => true] to decrypt and return it (owner-only paths).
     *
     * @param array<string, mixed> $whereParams
     * @param array{includeDecryptedKey?: bool} $options
     * @return ApiTokenRow|null
     */
    public function getBy(array $whereParams = [], array $options = []): ?array
    {
        if ($whereParams === []) {
            return null;
        }

        $includeDecryptedKey = $options['includeDecryptedKey'] ?? false;

        $selectFields = $includeDecryptedKey ? [...self::SELECT_FIELDS, 'encryptedKey'] : self::SELECT_FIELDS;

        $token = $this->strapi->db()->query('admin::api-token')->findOne([
            'select' => $selectFields,
            'populate' => self::POPULATE_FIELDS,
            'where' => $whereParams,
        ]);

        if ($token === null) {
            return null;
        }

        // Tokens created before kind introduction case: force kind to be content-api
        $computedKind = $token['kind'] ?? 'content-api';

        $result = self::omit($token, ['accessKey', 'encryptedKey', 'type', 'permissions', 'adminPermissions', 'adminUserOwner']);

        if ($computedKind === 'content-api') {
            $result['kind'] = 'content-api';
            $result['type'] = $token['type'] ?? null;
            $result['permissions'] = self::flattenTokenPermissions($token['permissions'] ?? null);
        } elseif ($computedKind === 'admin') {
            $result['kind'] = 'admin';
            $result['adminPermissions'] = $token['adminPermissions'] ?? null;
            $result['adminUserOwner'] = self::toAdminTokenOwner($token['adminUserOwner'] ?? null);
        }

        if ($includeDecryptedKey && is_string($token['encryptedKey'] ?? null) && $token['encryptedKey'] !== '') {
            $result['accessKey'] = Utils::getService($this->strapi, 'encryption')->decrypt($token['encryptedKey']);
        }

        return $result;
    }

    /**
     * Check if token exists
     *
     * @param array<string, mixed> $whereParams
     */
    public function exists(array $whereParams = []): bool
    {
        return $this->getBy($whereParams) !== null;
    }

    /**
     * Return a secure sha512 hash of an accessKey
     */
    public function hash(string $accessKey): string
    {
        $salt = $this->strapi->config()->get('admin.apiToken.salt');

        return hash_hmac('sha512', $accessKey, is_scalar($salt) ? (string) $salt : '');
    }

    /**
     * Kind-agnostic lookup by hashed access key — used by the auth strategy.
     *
     * @param array{includeDecryptedKey?: bool} $options
     * @return ApiTokenRow|null
     */
    public function getByAccessKey(string $accessKeyHash, array $options = []): ?array
    {
        return $this->getBy(['accessKey' => $accessKeyHash], $options);
    }

    /**
     * @return array{authenticated: false, error?: UnauthorizedError}|array{authenticated: true, credentials: ApiTokenRow, user: array<string, mixed>, ability: Ability}
     */
    public function authenticateAdminToken(string $accessToken): array
    {
        $apiToken = $this->getBy(['accessKey' => $this->hash($accessToken)]);

        if ($apiToken === null) {
            return ['authenticated' => false];
        }

        if (($apiToken['kind'] ?? null) !== 'admin') {
            return ['authenticated' => false];
        }

        $expiryError = ApiTokenUtils::checkExpiry($apiToken);
        if ($expiryError !== null) {
            return ['authenticated' => false, 'error' => $expiryError];
        }

        $ownerId = self::resolveAdminTokenOwnerId($apiToken);
        if ($ownerId === null) {
            return ['authenticated' => false, 'error' => new UnauthorizedError('Token owner not found')];
        }

        $user = $this->strapi->db()->query('admin::user')->findOne(['where' => ['id' => $ownerId], 'populate' => ['roles']]);

        if ($user === null) {
            return ['authenticated' => false, 'error' => new UnauthorizedError('Token owner not found')];
        }

        if (($user['isActive'] ?? null) !== true || ($user['blocked'] ?? null) === true) {
            return ['authenticated' => false, 'error' => new UnauthorizedError('Token owner is deactivated')];
        }

        ApiTokenUtils::updateLastUsedAt($this->strapi, $apiToken);

        $adminPermissions = is_array($apiToken['adminPermissions'] ?? null) ? array_values($apiToken['adminPermissions']) : [];
        $ability = $this->permissionService()->engine->generateTokenAbility($adminPermissions, $user);

        return ['authenticated' => true, 'credentials' => $apiToken, 'user' => $user, 'ability' => $ability];
    }

    /** @return array{lifespan: mixed, expiresAt: int|null} */
    private static function getExpirationFields(mixed $lifespan): array
    {
        // it must be nil or a finite number >= 0
        $isValidNumber = (is_int($lifespan) || is_float($lifespan)) && is_finite((float) $lifespan) && $lifespan > 0;
        if (!$isValidNumber && $lifespan !== null) {
            throw new ValidationError('lifespan must be a positive number or null');
        }

        return [
            'lifespan' => $lifespan,
            'expiresAt' => $lifespan ? self::nowMs() + (int) $lifespan : null,
        ];
    }

    /** `Date.now()` */
    private static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /**
     * `uniq()`, keeping first-seen order.
     *
     * @param list<mixed> $values
     * @return list<mixed>
     */
    private static function uniq(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (!in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * Create a token and its permissions (upstream's module-level `create`, the kind given in
     * `$attributes['kind']`).
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed>|null $callingUser
     * @return ApiTokenRow
     */
    public function createToken(array $attributes, ?array $callingUser = null): array
    {
        $encryptionService = Utils::getService($this->strapi, 'encryption');
        $accessKey = bin2hex(random_bytes(128));
        $encryptedKey = $encryptionService->encrypt($accessKey);

        self::assertValidLifespan($attributes['lifespan'] ?? null);

        if (($attributes['kind'] ?? null) === 'content-api') {
            self::assertLegacyKindFields($attributes);
            $this->assertCustomTokenPermissionsValidity($attributes['type'] ?? null, $attributes['permissions'] ?? null);

            // content api tokens have no owner
            $apiToken = $this->strapi->db()->query('admin::api-token')->create([
                'select' => self::SELECT_FIELDS,
                'populate' => self::POPULATE_FIELDS,
                'data' => [
                    ...self::omit($attributes, ['permissions', 'adminPermissions', 'adminUserOwner']),
                    'accessKey' => $this->hash($accessKey),
                    'encryptedKey' => $encryptedKey,
                    'adminUserOwner' => null,
                    ...self::getExpirationFields($attributes['lifespan'] ?? null),
                ],
            ]);

            $result = [...$apiToken, 'accessKey' => $accessKey];

            // If this is a custom type token, create the related content-API permissions
            if (($attributes['type'] ?? null) === Constants::API_TOKEN_TYPE['CUSTOM']) {
                // TODO: createMany doesn't seem to create relation properly, implement a better way rather than a ton of queries
                $permissions = is_array($attributes['permissions'] ?? null) ? array_values($attributes['permissions']) : [];
                foreach (self::uniq($permissions) as $action) {
                    $this->strapi->db()->query('admin::api-token-permission')->create([
                        'data' => ['action' => $action, 'token' => $apiToken['id']],
                    ]);
                }

                $currentPermissions = $this->strapi->db()->query('admin::api-token')->load($apiToken, 'permissions');

                if ($currentPermissions !== null && $currentPermissions !== false) {
                    $result['permissions'] = self::flattenTokenPermissions($currentPermissions);
                }
            }

            AuditLogs::emitAudit($this->strapi, Tokens::AUDITED_EVENTS['TOKEN_CREATE'], [
                'tokenId' => $apiToken['id'] ?? null,
                'name' => $apiToken['name'] ?? null,
                'kind' => 'content-api',
                'description' => $apiToken['description'] ?? null,
                'lifespan' => $apiToken['lifespan'] ?? null,
                'expiresAt' => $apiToken['expiresAt'] ?? null,
                'type' => $apiToken['type'] ?? null,
                ...(($apiToken['type'] ?? null) === Constants::API_TOKEN_TYPE['CUSTOM'] ? [
                    'permissions' => $result['permissions'] ?? [],
                ] : []),
            ]);

            return self::omit($result, ['adminPermissions', 'adminUserOwner']);
        }

        // kind === 'admin'
        self::assertAdminKindFields($attributes);
        $requestedAdminPermissions = is_array($attributes['adminPermissions'] ?? null) ? array_values($attributes['adminPermissions']) : null;
        $this->assertAdminPermissionsValidity($requestedAdminPermissions);
        $clampedAdminPermissions = $this->enforceAdminPermissionsCeiling($callingUser, $requestedAdminPermissions);

        // Owner: when explicitly provided, it must match the caller.
        // When omitted, always defaults to the calling user (including super admins).
        if (($attributes['adminUserOwner'] ?? null) !== null) {
            $this->assertOwnerMatchesCallingUser($attributes['adminUserOwner'], $callingUser);
            $ownerId = $attributes['adminUserOwner'];
        } else {
            if ($callingUser === null) {
                throw new ValidationError('Creating an admin token requires an authenticated admin user');
            }
            $ownerId = $callingUser['id'] ?? null;
        }

        $apiToken = $this->strapi->db()->query('admin::api-token')->create([
            'select' => self::SELECT_FIELDS,
            'populate' => self::POPULATE_FIELDS,
            'data' => [
                ...self::omit($attributes, ['permissions', 'adminPermissions', 'adminUserOwner']),
                'accessKey' => $this->hash($accessKey),
                'encryptedKey' => $encryptedKey,
                'adminUserOwner' => $ownerId,
                ...self::getExpirationFields($attributes['lifespan'] ?? null),
            ],
        ]);

        $result = [...$apiToken, 'accessKey' => $accessKey];

        // Handle admin permissions (using ceiling-clamped permissions with inherited conditions)
        if ($clampedAdminPermissions !== []) {
            $this->createApiTokenAdminPermissions($apiToken['id'] ?? null, $clampedAdminPermissions);

            $currentAdminPermissions = $this->strapi->db()->query('admin::api-token')->load($apiToken, 'adminPermissions');

            if ($currentAdminPermissions !== null && $currentAdminPermissions !== false) {
                $result['adminPermissions'] = $currentAdminPermissions;
            }
        }

        AuditLogs::emitAudit($this->strapi, Tokens::AUDITED_EVENTS['TOKEN_CREATE'], [
            'tokenId' => $apiToken['id'] ?? null,
            'name' => $apiToken['name'] ?? null,
            'kind' => 'admin',
            'adminUserOwner' => $ownerId,
            'description' => $apiToken['description'] ?? null,
            'lifespan' => $apiToken['lifespan'] ?? null,
            'expiresAt' => $apiToken['expiresAt'] ?? null,
            'permissions' => Tokens::toAdminPermissionRefs(is_array($result['adminPermissions'] ?? null) ? $result['adminPermissions'] : null),
        ]);

        return [
            ...self::omit($result, ['permissions']),
            'adminUserOwner' => self::toAdminTokenOwner($result['adminUserOwner'] ?? null),
        ];
    }

    /** @return ApiTokenRow */
    public function regenerate(mixed $id): array
    {
        $accessKey = bin2hex(random_bytes(128));
        $encryptedKey = Utils::getService($this->strapi, 'encryption')->encrypt($accessKey);

        $apiToken = $this->strapi->db()->query('admin::api-token')->update([
            'select' => ['id', 'name', 'accessKey', 'kind'],
            // The owner id only, for the audit row; it is stripped from the response below
            'populate' => ['adminUserOwner' => ['select' => ['id']]],
            'where' => ['id' => $id],
            'data' => [
                'accessKey' => $this->hash($accessKey),
                'encryptedKey' => $encryptedKey,
            ],
        ]);

        if ($apiToken === null) {
            throw new NotFoundError('The provided token id does not exist');
        }

        $ownerId = ($apiToken['kind'] ?? null) === 'admin' ? self::resolveAdminTokenOwnerId($apiToken) : null;

        AuditLogs::emitAudit($this->strapi, Tokens::AUDITED_EVENTS['TOKEN_REGENERATE'], [
            'tokenId' => $apiToken['id'] ?? null,
            'name' => $apiToken['name'] ?? null,
            'kind' => $apiToken['kind'] ?? 'content-api',
            ...($ownerId !== null ? ['adminUserOwner' => $ownerId] : []),
        ]);

        return [
            ...self::omit($apiToken, ['adminUserOwner']),
            'kind' => $apiToken['kind'] ?? 'content-api',
            'accessKey' => $accessKey,
        ];
    }

    public function checkSaltIsDefined(): void
    {
        $apiTokenCfg = $this->strapi->config()->get('admin.apiToken');
        if (!is_array($apiTokenCfg) || empty($apiTokenCfg['salt'])) {
            // TODO V5: stop reading API_TOKEN_SALT
            $envSalt = getenv('API_TOKEN_SALT');
            if (is_string($envSalt) && $envSalt !== '') {
                $this->strapi->log()->warning("[deprecated] In future versions, Strapi will stop reading directly from the environment variable API_TOKEN_SALT. Please set apiToken.salt in config/admin.js instead.\nFor security reasons, keep storing the secret in an environment variable and use env() to read it in config/admin.js (ex: `apiToken: { salt: env('API_TOKEN_SALT') }`). See https://docs.strapi.io/developer-docs/latest/setup-deployment-guides/configurations/optional/environment.html#configuration-using-environment-variables.");

                $this->strapi->config()->set('admin.apiToken.salt', $envSalt);
            } else {
                throw new \RuntimeException("Missing apiToken.salt. Please set apiToken.salt in config/admin.js (ex: you can generate one using Node with `crypto.randomBytes(16).toString('base64')`).\nFor security reasons, prefer storing the secret in an environment variable and read it in config/admin.js. See https://docs.strapi.io/developer-docs/latest/setup-deployment-guides/configurations/optional/environment.html#configuration-using-environment-variables.");
            }
        }
    }

    /**
     * Return a list of tokens visible to the calling user (upstream's module-level `list`).
     * Super-admins see all tokens; regular admins see only ownerless tokens and their own.
     *
     * @param array<string, mixed> $callingUser
     * @param array{filter?: array{kind?: string}} $options
     * @return list<ApiTokenRow>
     */
    public function listTokens(array $callingUser, array $options = []): array
    {
        $ownershipWhere = self::isSuperAdmin($callingUser)
            ? []
            : ['$or' => [['adminUserOwner' => null], ['adminUserOwner' => ['id' => $callingUser['id'] ?? null]]]];

        // Tokens without a persisted kind are content-api tokens (pre-migration rows).
        $filterKind = $options['filter']['kind'] ?? null;
        $kindWhere = [];
        if ($filterKind === 'content-api') {
            $kindWhere = ['$or' => [['kind' => 'content-api'], ['kind' => ['$null' => true]]]];
        } elseif ($filterKind !== null) {
            $kindWhere = ['kind' => $filterKind];
        }

        // `{ ...ownershipWhere, ...kindWhere }`: a kind `$or` replaces the ownership one, as upstream
        $where = [...$ownershipWhere, ...$kindWhere];

        $tokens = $this->strapi->db()->query('admin::api-token')->findMany([
            'select' => self::SELECT_FIELDS,
            'populate' => self::POPULATE_FIELDS,
            'orderBy' => ['name' => 'ASC'],
            'where' => $where,
        ]);

        return array_map(static function (array $token): array {
            if (($token['kind'] ?? null) === null || $token['kind'] === 'content-api') {
                return self::omit([
                    ...$token,
                    // Tokens created before kind introduction case: force kind to be content-api
                    'kind' => 'content-api',
                    'permissions' => self::flattenTokenPermissions($token['permissions'] ?? null),
                ], ['adminPermissions', 'adminUserOwner']);
            }

            return [
                ...self::omit($token, ['permissions']),
                'adminUserOwner' => ($token['adminUserOwner'] ?? null) !== null
                    ? self::toAdminTokenOwner($token['adminUserOwner'])
                    : null,
            ];
        }, array_values($tokens));
    }

    /**
     * Revoke (delete) a token
     *
     * @return ApiTokenRow|null
     */
    public function revoke(mixed $id): ?array
    {
        $token = $this->strapi->db()->query('admin::api-token')->findOne([
            'where' => ['id' => $id],
            'select' => ['id'],
            'populate' => ['adminPermissions'],
        ]);

        if ($token !== null) {
            $permissionIds = array_values(array_filter(
                array_map(static fn (mixed $p): mixed => is_array($p) ? ($p['id'] ?? null) : null, is_array($token['adminPermissions'] ?? null) ? $token['adminPermissions'] : []),
                static fn (mixed $permId): bool => $permId !== null,
            ));

            if ($permissionIds !== []) {
                $this->permissionService()->deleteByIds($permissionIds);
            }
        }

        $deletedToken = $this->strapi->db()
            ->query('admin::api-token')
            ->delete(['select' => self::SELECT_FIELDS, 'populate' => self::POPULATE_FIELDS, 'where' => ['id' => $id]]);

        if ($deletedToken === null) {
            return null;
        }

        $ownerId = ($deletedToken['kind'] ?? null) === 'admin' ? self::resolveAdminTokenOwnerId($deletedToken) : null;

        AuditLogs::emitAudit($this->strapi, Tokens::AUDITED_EVENTS['TOKEN_DELETE'], [
            'tokenId' => $deletedToken['id'] ?? null,
            'name' => $deletedToken['name'] ?? null,
            'kind' => $deletedToken['kind'] ?? 'content-api',
            ...($ownerId !== null ? ['adminUserOwner' => $ownerId] : []),
        ]);

        if (($deletedToken['kind'] ?? null) === 'admin') {
            return [
                ...$deletedToken,
                'adminUserOwner' => self::toAdminTokenOwner($deletedToken['adminUserOwner'] ?? null),
            ];
        }

        // content-api tokens (including legacy null-kind rows): normalise shape
        return self::omit([
            ...$deletedToken,
            'kind' => 'content-api',
            'permissions' => self::flattenTokenPermissions($deletedToken['permissions'] ?? null),
        ], ['adminPermissions', 'adminUserOwner']);
    }

    /**
     * Update a token and its permissions
     *
     * @param array<string, mixed> $attributes
     * @return ApiTokenRow
     */
    public function update(mixed $id, array $attributes): array
    {
        $originalToken = $this->strapi->db()->query('admin::api-token')->findOne([
            'select' => self::SELECT_FIELDS,
            'populate' => ['adminUserOwner', 'permissions', 'adminPermissions'],
            'where' => ['id' => $id],
        ]);

        if ($originalToken === null) {
            throw new NotFoundError('Token not found');
        }

        $originalKind = $originalToken['kind'] ?? null;

        // Populated with its permissions: the audit row for this update lists what changed,
        // so the state before the write is needed. `type` is a content-api concept: admin
        // tokens only carry the column default.
        $previousSnapshot = [
            'name' => $originalToken['name'] ?? null,
            'description' => $originalToken['description'] ?? null,
            ...($originalKind !== 'admin' ? ['type' => $originalToken['type'] ?? null] : []),
            'permissions' => $originalKind === 'admin'
                ? Tokens::toAdminPermissionRefs(is_array($originalToken['adminPermissions'] ?? null) ? $originalToken['adminPermissions'] : null)
                : Tokens::toActionRefs(is_array($originalToken['permissions'] ?? null) ? $originalToken['permissions'] : null),
        ];

        $emitUpdate = function (string $kind, array $next, mixed $adminUserOwner = null) use ($previousSnapshot, $originalToken): void {
            $changes = Tokens::getTokenChanges($previousSnapshot, $next);

            if ($changes === []) {
                return;
            }

            AuditLogs::emitAudit($this->strapi, Tokens::AUDITED_EVENTS['TOKEN_UPDATE'], [
                'tokenId' => $originalToken['id'] ?? null,
                'name' => $next['name'] ?? null,
                'kind' => $kind,
                'changes' => $changes,
                ...($adminUserOwner !== null ? ['adminUserOwner' => $adminUserOwner] : []),
            ]);
        };

        $raw = $attributes;

        // kind is immutable after creation.
        // Null-kind rows are legacy content-api tokens — treat null and 'content-api' as the same
        // effective value so that clients echoing back the normalised kind from a GET don't get rejected.
        $effectiveStoredKind = $originalKind ?? 'content-api';
        if (($raw['kind'] ?? null) !== null && $raw['kind'] !== $effectiveStoredKind) {
            throw new ValidationError('kind is immutable after creation');
        }

        $clampedAdminPermissions = null;
        $tokenOwnerUser = null;

        if ($originalKind === null || $originalKind === 'content-api') {
            self::assertLegacyKindFields($attributes);

            $incomingType = $raw['type'] ?? null;
            $hasIncomingPermissions = array_key_exists('permissions', $raw);
            $incomingPermissions = $raw['permissions'] ?? null;
            $resolvedType = $incomingType ?? ($originalToken['type'] ?? null);
            $changingTypeToCustom = $incomingType === Constants::API_TOKEN_TYPE['CUSTOM']
                && ($originalToken['type'] ?? null) !== Constants::API_TOKEN_TYPE['CUSTOM'];

            // Only re-validate if permissions or type are being changed
            if ($hasIncomingPermissions || $changingTypeToCustom) {
                $this->assertCustomTokenPermissionsValidity($resolvedType, $incomingPermissions);
            }
        } elseif ($originalKind === 'admin') {
            self::assertAdminKindFields($attributes);

            if (array_key_exists('adminPermissions', $raw)) {
                $incomingAdminPermissions = is_array($raw['adminPermissions']) ? array_values($raw['adminPermissions']) : null;
                $this->assertAdminPermissionsValidity($incomingAdminPermissions);

                // Ceiling is always the owner's permissions, not the calling user's.
                // A super admin editing another user's token must not overflow that user's scope.
                $ownerId = self::getOwnerId($originalToken);
                $resolvedOwner = Utils::getService($this->strapi, 'user')->findOne($ownerId);
                if ($resolvedOwner === null) {
                    throw new ValidationError('Token owner no longer exists');
                }
                $tokenOwnerUser = $resolvedOwner;
                $clampedAdminPermissions = $this->enforceAdminPermissionsCeiling($tokenOwnerUser, $incomingAdminPermissions);
            }

            if (array_key_exists('adminUserOwner', $raw)) {
                $incomingAdminUserOwner = $raw['adminUserOwner'];
                // Owner is immutable; the provided value must match the existing one
                $existingOwner = $originalToken['adminUserOwner'] ?? null;
                $existingOwnerId = $existingOwner === null
                    ? null
                    : self::idString(is_array($existingOwner) ? ($existingOwner['id'] ?? null) : $existingOwner);
                $requestedOwnerId = $incomingAdminUserOwner === null ? null : self::idString($incomingAdminUserOwner);

                if ($requestedOwnerId !== $existingOwnerId) {
                    throw new ValidationError('adminUserOwner cannot be changed on update');
                }
            }
        }

        $baseData = [];
        foreach (self::UPDATABLE_FIELDS as $field) {
            if (array_key_exists($field, $attributes)) {
                $baseData[$field] = $attributes[$field];
            }
        }

        // Migrate legacy null-kind rows to the explicit value on first write
        if ($originalKind === null) {
            $baseData['kind'] = 'content-api';
        }

        $updatedToken = $this->strapi->db()->query('admin::api-token')->update([
            'select' => self::SELECT_FIELDS,
            'where' => ['id' => $id],
            'data' => $baseData,
        ]) ?? throw new NotFoundError('Token not found');

        if ($originalKind === null || $originalKind === 'content-api') {
            // custom tokens need to have their permissions updated as well
            if (($updatedToken['type'] ?? null) === Constants::API_TOKEN_TYPE['CUSTOM'] && array_key_exists('permissions', $raw)) {
                $currentPermissionsResult = $this->strapi->db()->query('admin::api-token')->load($updatedToken, 'permissions');

                $currentPermissions = self::flattenTokenPermissions(is_array($currentPermissionsResult) ? $currentPermissionsResult : []);
                $newPermissions = self::uniq(is_array($raw['permissions']) ? array_values($raw['permissions']) : []);

                $actionsToDelete = array_values(array_filter($currentPermissions, static fn (mixed $a): bool => !in_array($a, $newPermissions, true)));
                $actionsToAdd = array_values(array_filter($newPermissions, static fn (mixed $a): bool => !in_array($a, $currentPermissions, true)));

                // TODO: improve efficiency here
                foreach ($actionsToDelete as $action) {
                    $this->strapi->db()->query('admin::api-token-permission')->delete([
                        'where' => ['action' => $action, 'token' => $id],
                    ]);
                }

                // TODO: improve efficiency here
                foreach ($actionsToAdd as $action) {
                    $this->strapi->db()->query('admin::api-token-permission')->create([
                        'data' => ['action' => $action, 'token' => $id],
                    ]);
                }
            }
            // if type is not custom, make sure any old permissions get removed
            elseif (($updatedToken['type'] ?? null) !== Constants::API_TOKEN_TYPE['CUSTOM']) {
                $this->strapi->db()->query('admin::api-token-permission')->delete([
                    'where' => ['token' => $id],
                ]);
            }

            $permissionsFromDb = $this->strapi->db()->query('admin::api-token')->load($updatedToken, 'permissions');

            $permissions = is_array($permissionsFromDb) ? Tokens::toActionRefs($permissionsFromDb) : null;

            $emitUpdate('content-api', [
                'name' => $updatedToken['name'] ?? null,
                'description' => $updatedToken['description'] ?? null,
                'type' => $updatedToken['type'] ?? null,
                'permissions' => $permissions ?? [],
            ]);

            $result = $updatedToken;
            if ($permissions !== null) {
                $result['permissions'] = $permissions;
            }

            return $result;
        }

        // kind === 'admin'
        if ($clampedAdminPermissions !== null) {
            if ($tokenOwnerUser === null) {
                throw new ValidationError('Updating admin permissions requires a resolved token owner');
            }
            $this->assignAdminPermissionsToToken($id, $clampedAdminPermissions, $tokenOwnerUser);
        }

        $adminPermissionsFromDb = $this->strapi->db()->query('admin::api-token')->load($updatedToken, 'adminPermissions');

        $adminUserOwnerFromDb = $this->strapi->db()->query('admin::api-token')->load($updatedToken, 'adminUserOwner');

        $emitUpdate(
            'admin',
            [
                'name' => $updatedToken['name'] ?? null,
                'description' => $updatedToken['description'] ?? null,
                'permissions' => Tokens::toAdminPermissionRefs(is_array($adminPermissionsFromDb) ? array_values($adminPermissionsFromDb) : []),
            ],
            self::resolveAdminTokenOwnerId($originalToken),
        );

        return [
            ...$updatedToken,
            'adminPermissions' => is_array($adminPermissionsFromDb) ? $adminPermissionsFromDb : [],
            'adminUserOwner' => self::toAdminTokenOwner($adminUserOwnerFromDb),
        ];
    }

    /** @param array<string, mixed> $where */
    public function count(array $where = []): int
    {
        return $this->strapi->db()->query('admin::api-token')->count(['where' => $where]);
    }

    /**
     * Total count across all kinds.
     *
     * @param array<string, mixed> $where
     */
    public function countAll(array $where = []): int
    {
        return $this->count($where);
    }

    /**
     * Delete all admin API tokens owned by the given user, including their associated admin permissions.
     * Called when the owner user is deleted so tokens don't linger with a dangling owner FK.
     */
    public function deleteAdminTokensForUser(mixed $userId): void
    {
        $tokens = $this->strapi->db()->query('admin::api-token')->findMany([
            'where' => ['kind' => 'admin', 'adminUserOwner' => ['id' => $userId]],
            'select' => ['id'],
            'populate' => ['adminPermissions'],
        ]);

        foreach ($tokens as $token) {
            $permissionIds = array_values(array_filter(
                array_map(static fn (mixed $p): mixed => is_array($p) ? ($p['id'] ?? null) : null, is_array($token['adminPermissions'] ?? null) ? $token['adminPermissions'] : []),
                static fn (mixed $id): bool => $id !== null,
            ));

            if ($permissionIds !== []) {
                $this->permissionService()->deleteByIds($permissionIds);
            }

            $this->strapi->db()->query('admin::api-token')->delete(['where' => ['id' => $token['id'] ?? null]]);
        }
    }

    // -------------------------------------------------------------------------
    // Kind-bound service methods (createTokenService)
    // -------------------------------------------------------------------------

    /** `{ $or: [{ kind: 'content-api' }, { kind: { $null: true } }] }` */
    private const CONTENT_API_KIND_WHERE = ['$or' => [['kind' => 'content-api'], ['kind' => ['$null' => true]]]];

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed>|null $callingUser
     * @return ApiTokenRow
     */
    public function create(array $attributes, ?array $callingUser = null): array
    {
        return $this->createToken([...$attributes, 'kind' => $this->kind], $callingUser);
    }

    /**
     * @param array<string, mixed> $callingUser
     * @return list<ApiTokenRow>
     */
    public function list(array $callingUser): array
    {
        return $this->listTokens($callingUser, ['filter' => ['kind' => $this->kind]]);
    }

    /**
     * @param array{includeDecryptedKey?: bool} $options
     * @return ApiTokenRow|null
     */
    public function getById(mixed $id, array $options = []): ?array
    {
        if ($this->kind === 'content-api') {
            return $this->getBy(['$and' => [['id' => $id], self::CONTENT_API_KIND_WHERE]], $options);
        }

        return $this->getBy(['id' => $id, 'kind' => 'admin'], $options);
    }

    /**
     * @param array{includeDecryptedKey?: bool} $options
     * @return ApiTokenRow|null
     */
    public function getByName(string $name, array $options = []): ?array
    {
        if ($this->kind === 'content-api') {
            return $this->getBy(['$and' => [['name' => $name], self::CONTENT_API_KIND_WHERE]], $options);
        }

        return $this->getBy(['name' => $name, 'kind' => 'admin'], $options);
    }

    public function syncPermissionsForUser(mixed $userId): void
    {
        $this->syncApiTokenPermissionsForUser($userId);
    }

    public function syncPermissionsForRole(mixed $roleId): void
    {
        $this->syncApiTokenPermissionsForRole($roleId);
    }

    public function deleteTokensForUser(mixed $userId): void
    {
        $this->deleteAdminTokensForUser($userId);
    }
}
