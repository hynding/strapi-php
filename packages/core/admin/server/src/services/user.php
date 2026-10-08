<?php

declare(strict_types=1);

namespace Strapi\Admin\Services;

use Strapi\Admin\AuditLogs\AdminUsers;
use Strapi\Admin\Domain\User as UserDomain;
use Strapi\Admin\Utils\Utils;
use Strapi\Admin\Validation\CommonValidators;
use Strapi\Core\Services\SessionManager;
use Strapi\Core\Strapi;
use Strapi\Utils\AuditLogs;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Primitives\Objects;

/** Port of server/src/services/user.ts (`admin::user`). Users are the `admin::user` rows (arrays). */
final class User
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * @param array<string, mixed> $role
     * @return array<string, mixed>
     */
    private static function sanitizeUserRoles(array $role): array
    {
        /** @var array<string, mixed> */
        return Objects::pick($role, ['id', 'name', 'description', 'code']);
    }

    private function getSessionManager(): ?SessionManager
    {
        return $this->strapi->has('sessionManager') ? $this->strapi->sessionManager() : null;
    }

    /**
     * Remove private user fields
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function sanitizeUser(array $user): array
    {
        $roles = $user['roles'] ?? null;

        /** @var array<string, mixed> $sanitized */
        $sanitized = Objects::omit($user, ['password', 'resetPasswordToken', 'resetPasswordTokenExpiresAt', 'registrationToken', 'roles']);
        // upstream: `roles: user.roles && user.roles.map(sanitizeUserRoles)` (undefined is dropped from JSON)
        if (is_array($roles)) {
            $sanitized['roles'] = array_map(static fn (mixed $role): mixed => is_array($role) ? self::sanitizeUserRoles($role) : $role, array_values($roles));
        } elseif (array_key_exists('roles', $user)) {
            $sanitized['roles'] = $roles;
        }

        return $sanitized;
    }

    /**
     * Create and save a user in database
     *
     * @param array<string, mixed> $attributes A partial user object
     * @return array<string, mixed>
     */
    private function createUserInDatabase(array $attributes): array
    {
        $userInfo = [
            'registrationToken' => Utils::getService($this->strapi, 'token')->createToken(),
            ...$attributes,
        ];

        if (array_key_exists('password', $attributes)) {
            $userInfo['password'] = Utils::getService($this->strapi, 'auth')->hashPassword((string) $attributes['password']);
        }

        $user = UserDomain::createUser($userInfo);

        return $this->strapi->db()->query('admin::user')->create(['data' => $user, 'populate' => ['roles']]);
    }

    /** @param array<string, mixed> $user */
    private function emitUserCreated(array $user): void
    {
        Utils::getService($this->strapi, 'metrics')->sendDidInviteUser();

        $this->strapi->eventHub()->emit(AdminUsers::LEGACY_USER_EVENTS['CREATE'], ['user' => $this->sanitizeUser($user)]);
        AdminUsers::emitAdminUserCreated($this->strapi, $user);
    }

    /**
     * @param array<string, mixed> $attributes isActive is added in the controller, it's not sent by the API.
     * @return array<string, mixed>
     */
    public function create(array $attributes): array
    {
        $createdUser = $this->createUserInDatabase($attributes);

        $this->emitUserCreated($createdUser);

        return $createdUser;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function createFirstAdmin(array $attributes): array
    {
        $createdUser = $this->strapi->db()->transaction(function (array $ctx) use ($attributes): array {
            // Serialize first-admin creation across processes by locking a stable role row.
            // SQLite ignores FOR UPDATE, but falls back to its single-writer transaction behavior.
            $superAdminRole = $this->strapi->db()
                ->queryBuilder('admin::role')
                ->select(['id'])
                ->where(['code' => Constants::SUPER_ADMIN_CODE])
                ->first()
                ->transacting($ctx['trx'])
                ->forUpdate()
                ->execute();

            if (!is_array($superAdminRole) || $superAdminRole === []) {
                throw new ApplicationError("Cannot register the first admin because the super admin role doesn't exist.");
            }

            $hasAdmin = $this->exists();

            if ($hasAdmin) {
                throw new ApplicationError('You cannot register a new super admin');
            }

            return $this->createUserInDatabase([
                ...$attributes,
                'registrationToken' => null,
                'isActive' => true,
                'roles' => [$superAdminRole['id']],
            ]);
        });

        $this->emitUserCreated($createdUser);

        return $createdUser;
    }

    /**
     * Update a user in database
     *
     * @param array<string, mixed> $attributes A partial user object
     * @return array<string, mixed>|null
     */
    public function updateById(mixed $id, array $attributes): ?array
    {
        // Check at least one super admin remains
        if (array_key_exists('roles', $attributes)) {
            $lastAdminUser = $this->isLastSuperAdminUser($id);
            $superAdminRole = Utils::getService($this->strapi, 'role')->getSuperAdminWithUsersCount();
            $roleIds = array_map(static fn (mixed $role): string => (string) (is_array($role) ? ($role['id'] ?? '') : $role), is_array($attributes['roles']) ? $attributes['roles'] : []);
            $willRemoveSuperAdminRole = !in_array((string) ($superAdminRole['id'] ?? ''), $roleIds, true);

            if ($lastAdminUser && $willRemoveSuperAdminRole) {
                throw new ValidationError('You must have at least one user with super admin role.');
            }
        }

        // cannot disable last super admin
        if (array_key_exists('isActive', $attributes) && $attributes['isActive'] === false) {
            $lastAdminUser = $this->isLastSuperAdminUser($id);
            if ($lastAdminUser) {
                throw new ValidationError('You must have at least one user with super admin role.');
            }
        }

        // The audit log records what changed, so it needs the row before the write
        $previous = AdminUsers::touchesTrackedFields($attributes)
            ? $this->strapi->db()->query('admin::user')->findOne(['where' => ['id' => $id], 'populate' => ['roles']])
            : null;

        // hash password if a new one is sent
        if (array_key_exists('password', $attributes)) {
            $hashedPassword = Utils::getService($this->strapi, 'auth')->hashPassword((string) $attributes['password']);

            $updatedUser = $this->strapi->db()->query('admin::user')->update([
                'where' => ['id' => $id],
                'data' => [...$attributes, 'password' => $hashedPassword],
                'populate' => ['roles'],
            ]);

            $this->strapi->eventHub()->emit(AdminUsers::LEGACY_USER_EVENTS['UPDATE'], ['user' => is_array($updatedUser) ? $this->sanitizeUser($updatedUser) : null]);
            AdminUsers::emitAdminUserUpdateAudits($this->strapi, $previous, $updatedUser, $attributes);

            return $updatedUser;
        }

        $updatedUser = $this->strapi->db()->query('admin::user')->update([
            'where' => ['id' => $id],
            'data' => $attributes,
            'populate' => ['roles'],
        ]);

        if ($updatedUser !== null) {
            $this->strapi->eventHub()->emit(AdminUsers::LEGACY_USER_EVENTS['UPDATE'], ['user' => $this->sanitizeUser($updatedUser)]);
            AdminUsers::emitAdminUserUpdateAudits($this->strapi, $previous, $updatedUser, $attributes);
        }

        return $updatedUser;
    }

    /** Reset a user password by email. (Used in admin:reset CLI) */
    public function resetPasswordByEmail(string $email, string $password): void
    {
        $user = $this->strapi->db()->query('admin::user')->findOne(['where' => ['email' => $email], 'populate' => ['roles']]);

        if ($user === null) {
            throw new \RuntimeException("User not found for email: {$email}");
        }

        try {
            CommonValidators::password()->validateSync($password);
        } catch (\Throwable) {
            throw new ValidationError('Invalid password. Expected a minimum of 8 characters with at least one number and one uppercase letter');
        }

        $this->updateById($user['id'], ['password' => $password]);
    }

    /** Check if a user is the last super admin */
    public function isLastSuperAdminUser(mixed $userId): bool
    {
        $user = $this->findOne($userId);
        if ($user === null) {
            return false;
        }

        $superAdminRole = Utils::getService($this->strapi, 'role')->getSuperAdminWithUsersCount();

        return ($superAdminRole['usersCount'] ?? null) === 1 && UserDomain::hasSuperAdminRole($user);
    }

    /** Check if a user is the first super admin */
    public function isFirstSuperAdminUser(mixed $userId): bool
    {
        $currentUser = $this->findOne($userId);

        if ($currentUser === null || !UserDomain::hasSuperAdminRole($currentUser)) {
            return false;
        }

        $users = $this->strapi->db()->query('admin::user')->findMany([
            'populate' => [
                'roles' => [
                    'where' => ['code' => ['$eq' => Constants::SUPER_ADMIN_CODE]],
                ],
            ],
            'orderBy' => ['createdAt' => 'asc'],
            'limit' => 1,
            'select' => ['id'],
        ]);
        $oldestUser = $users[0] ?? null;

        return is_array($oldestUser) && $oldestUser['id'] === $currentUser['id'];
    }

    /**
     * Check if a user with specific attributes exists in the database
     *
     * @param array<string, mixed> $attributes A partial user object
     */
    public function exists(array $attributes = []): bool
    {
        return $this->strapi->db()->query('admin::user')->count(['where' => $attributes]) > 0;
    }

    /**
     * Returns a user registration info
     *
     * @return array<string, mixed>|null user email, firstname and lastname
     */
    public function findRegistrationInfo(string $registrationToken): ?array
    {
        $user = $this->strapi->db()->query('admin::user')->findOne(['where' => ['registrationToken' => $registrationToken]]);

        if ($user === null) {
            return null;
        }

        /** @var array<string, mixed> */
        return Objects::pick($user, ['email', 'firstname', 'lastname']);
    }

    /**
     * Registers a user based on a registrationToken and some informations to update
     *
     * @param array{registrationToken?: mixed, userInfo?: array<string, mixed>} $params
     * @return array<string, mixed>|null
     */
    public function register(array $params): ?array
    {
        $userInfo = $params['userInfo'] ?? [];
        $matchingUser = $this->strapi->db()->query('admin::user')->findOne(['where' => ['registrationToken' => $params['registrationToken'] ?? null]]);

        if ($matchingUser === null) {
            throw new ValidationError('Invalid registration info');
        }

        // upstream passes `undefined` for missing fields, which the query layer ignores
        $provided = [];
        foreach (['password', 'firstname', 'lastname'] as $field) {
            if (is_array($userInfo) && array_key_exists($field, $userInfo)) {
                $provided[$field] = $userInfo[$field];
            }
        }
        $registeredUser = Utils::getService($this->strapi, 'user')->updateById($matchingUser['id'], [
            ...$provided,
            'registrationToken' => null,
            'isActive' => true,
        ]);

        AuditLogs::emitAudit($this->strapi, AdminUsers::AUDITED_EVENTS['INVITE_ACCEPT'], AdminUsers::toAdminUserEvent(is_array($registeredUser) ? $registeredUser : []));

        return $registeredUser;
    }

    /**
     * Find one user
     *
     * @param list<string>|array<string, mixed> $populate
     * @return array<string, mixed>|null
     */
    public function findOne(mixed $id, array $populate = ['roles']): ?array
    {
        return $this->strapi->db()->query('admin::user')->findOne(['where' => ['id' => $id], 'populate' => $populate]);
    }

    /**
     * Find one user by its email
     *
     * @param list<string>|array<string, mixed> $populate
     * @return array<string, mixed>|null
     */
    public function findOneByEmail(string $email, array $populate = []): ?array
    {
        return $this->strapi->db()->query('admin::user')->findOne([
            'where' => ['email' => ['$eqi' => $email]],
            'populate' => $populate,
        ]);
    }

    /**
     * Find many users (paginated)
     *
     * @param array<string, mixed> $params
     * @return array{results: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function findPage(array $params = []): array
    {
        $query = $this->strapi->get('query-params')->transform('admin::user', [...$params, 'populate' => $params['populate'] ?? ['roles']]);

        /** @var array{results: list<array<string, mixed>>, pagination: array<string, mixed>} */
        return $this->strapi->db()->query('admin::user')->findPage($query);
    }

    /**
     * Delete a user
     *
     * @return array<string, mixed>|null
     */
    public function deleteById(mixed $id): ?array
    {
        // Check at least one super admin remains
        $userToDelete = $this->strapi->db()->query('admin::user')->findOne([
            'where' => ['id' => $id],
            'populate' => ['roles'],
        ]);

        if ($userToDelete === null) {
            return null;
        }

        foreach ($userToDelete['roles'] ?? [] as $role) {
            if (is_array($role) && ($role['code'] ?? null) === Constants::SUPER_ADMIN_CODE) {
                $superAdminRole = Utils::getService($this->strapi, 'role')->getSuperAdminWithUsersCount();
                if (($superAdminRole['usersCount'] ?? null) === 1) {
                    throw new ValidationError('You must have at least one user with super admin role.');
                }
                break;
            }
        }

        $deletedUser = $this->strapi->db()->query('admin::user')->delete(['where' => ['id' => $id], 'populate' => ['roles']]);

        // Invalidate all sessions for the deleted user
        $sessionManager = $this->getSessionManager();
        if ($sessionManager !== null && $sessionManager->hasOrigin('admin')) {
            $sessionManager('admin')->invalidateRefreshToken((string) $id);
        }

        $this->strapi->eventHub()->emit(AdminUsers::LEGACY_USER_EVENTS['DELETE'], ['user' => is_array($deletedUser) ? $this->sanitizeUser($deletedUser) : null]);
        AdminUsers::emitAdminUserDeleted($this->strapi, is_array($deletedUser) ? $deletedUser : []);

        return $deletedUser;
    }

    /**
     * Delete several users
     *
     * @param list<string|int> $ids
     * @return list<array<string, mixed>>
     */
    public function deleteByIds(array $ids): array
    {
        // Check at least one super admin remains
        $superAdminRole = Utils::getService($this->strapi, 'role')->getSuperAdminWithUsersCount();
        $nbOfSuperAdminToDelete = $this->strapi->db()->query('admin::user')->count([
            'where' => [
                'id' => $ids,
                'roles' => ['id' => $superAdminRole['id'] ?? null],
            ],
        ]);

        if (($superAdminRole['usersCount'] ?? null) === $nbOfSuperAdminToDelete) {
            throw new ValidationError('You must have at least one user with super admin role.');
        }

        $deletedUsers = [];
        foreach ($ids as $id) {
            $deletedUser = $this->strapi->db()->query('admin::user')->delete([
                'where' => ['id' => $id],
                'populate' => ['roles'],
            ]);

            // Invalidate all sessions for the deleted user
            $sessionManager = $this->getSessionManager();
            if ($sessionManager !== null && $sessionManager->hasOrigin('admin')) {
                $sessionManager('admin')->invalidateRefreshToken((string) $id);
            }

            $deletedUsers[] = $deletedUser;
        }

        $this->strapi->eventHub()->emit(AdminUsers::LEGACY_USER_EVENTS['DELETE'], [
            'users' => array_map(fn (mixed $deletedUser): ?array => is_array($deletedUser) ? $this->sanitizeUser($deletedUser) : null, $deletedUsers),
        ]);

        foreach ($deletedUsers as $deletedUser) {
            AdminUsers::emitAdminUserDeleted($this->strapi, is_array($deletedUser) ? $deletedUser : []);
        }

        /** @var list<array<string, mixed>> $deletedUsers */
        return $deletedUsers;
    }

    /** Count the users that don't have any associated roles */
    public function countUsersWithoutRole(): int
    {
        return $this->strapi->db()->query('admin::user')->count([
            'where' => ['roles' => ['id' => ['$null' => true]]],
        ]);
    }

    /**
     * Count the number of users based on search params
     *
     * @param array<string, mixed> $where
     */
    public function count(array $where = []): int
    {
        return $this->strapi->db()->query('admin::user')->count(['where' => $where]);
    }

    /** Assign some roles to several users */
    public function assignARoleToAll(mixed $roleId): void
    {
        $users = $this->strapi->db()->query('admin::user')->findMany([
            'select' => ['id'],
            'where' => ['roles' => ['id' => ['$null' => true]]],
        ]);

        foreach ($users as $user) {
            $this->strapi->db()->query('admin::user')->update([
                'where' => ['id' => $user['id']],
                'data' => ['roles' => [$roleId]],
            ]);
        }
    }

    /** Display a warning if some users don't have at least one role */
    public function displayWarningIfUsersDontHaveRole(): void
    {
        $count = $this->countUsersWithoutRole();

        if ($count > 0) {
            $this->strapi->log()->warning("Some users ({$count}) don't have any role.");
        }
    }

    /**
     * Returns an array of interface languages currently used by users
     *
     * @return list<string>
     */
    public function getLanguagesInUse(): array
    {
        $users = $this->strapi->db()->query('admin::user')->findMany(['select' => ['preferedLanguage']]);

        return array_values(array_map(static fn (array $user): string => is_string($user['preferedLanguage'] ?? null) && $user['preferedLanguage'] !== '' ? $user['preferedLanguage'] : 'en', $users));
    }
}
