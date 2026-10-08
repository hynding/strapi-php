<?php

declare(strict_types=1);

namespace Strapi\Admin\Services\Transfer;

use Strapi\Admin\AuditLogs\Tokens;
use Strapi\Admin\Services\Constants;
use Strapi\Core\Strapi;
use Strapi\Utils\AuditLogs;
use Strapi\Utils\Errors\NotFoundError;
use Strapi\Utils\Errors\ValidationError;

/**
 * Port of server/src/services/transfer/token.ts: transfer tokens (`admin::transfer-token`) and
 * their `push`/`pull` permissions. Tokens are plain arrays.
 *
 * @phpstan-type TransferToken array<string, mixed>
 */
final class Token
{
    private const TRANSFER_TOKEN_UID = 'admin::transfer-token';
    private const TRANSFER_TOKEN_PERMISSION_UID = 'admin::transfer-token-permission';

    private const SELECT_FIELDS = [
        'id',
        'name',
        'description',
        'lastUsedAt',
        'lifespan',
        'expiresAt',
        'createdAt',
        'updatedAt',
    ];

    private const POPULATE_FIELDS = ['permissions'];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    private function transfer(): Transfer
    {
        /** @var Transfer */
        return $this->strapi->service('admin::transfer');
    }

    /**
     * Return a list of all tokens and their permissions
     *
     * @return list<TransferToken>
     */
    public function list(): array
    {
        $tokens = $this->strapi->db()->query(self::TRANSFER_TOKEN_UID)->findMany([
            'select' => self::SELECT_FIELDS,
            'populate' => self::POPULATE_FIELDS,
            'orderBy' => ['name' => 'ASC'],
        ]);

        return array_map(self::flattenTokenPermissions(...), array_values($tokens));
    }

    /**
     * Create a random token's access key
     */
    private static function generateRandomAccessKey(): string
    {
        return bin2hex(random_bytes(128));
    }

    /**
     * Validate the given access key's format and returns it if valid
     */
    private static function validateAccessKey(mixed $accessKey): string
    {
        if (!is_string($accessKey)) {
            throw new \AssertionError('Access key needs to be a string');
        }
        if (strlen($accessKey) < 15) {
            throw new \AssertionError('Access key needs to have at least 15 characters');
        }

        return $accessKey;
    }

    /** @param array<string, mixed> $attributes */
    public static function hasAccessKey(array $attributes): bool
    {
        return array_key_exists('accessKey', $attributes);
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
     * Create a token and its permissions
     *
     * @param array<string, mixed> $attributes
     * @return TransferToken
     */
    public function create(array $attributes): array
    {
        $accessKey = self::hasAccessKey($attributes)
            ? self::validateAccessKey($attributes['accessKey'])
            : self::generateRandomAccessKey();

        // Make sure the access key isn't picked up directly from the attributes for the next steps
        unset($attributes['accessKey']);

        $this->assertTokenPermissionsValidity($attributes);
        self::assertValidLifespan($attributes['lifespan'] ?? null);

        $db = $this->strapi->db();
        /** @var TransferToken $result */
        $result = $db->transaction(function () use ($db, $attributes, $accessKey): array {
            $transferToken = $db->query(self::TRANSFER_TOKEN_UID)->create([
                'select' => self::SELECT_FIELDS,
                'populate' => self::POPULATE_FIELDS,
                'data' => [
                    ...array_diff_key($attributes, ['permissions' => true]),
                    'accessKey' => $this->hash($accessKey),
                    ...self::getExpirationFields($attributes['lifespan'] ?? null),
                ],
            ]);

            $permissions = is_array($attributes['permissions'] ?? null) ? array_values($attributes['permissions']) : [];
            foreach (self::uniq($permissions) as $action) {
                $db->query(self::TRANSFER_TOKEN_PERMISSION_UID)->create(['data' => ['action' => $action, 'token' => $transferToken['id']]]);
            }

            $currentPermissions = $db->query(self::TRANSFER_TOKEN_UID)->load($transferToken, 'permissions');

            if (is_array($currentPermissions)) {
                $transferToken['permissions'] = array_map(static fn (array $p): mixed => $p['action'] ?? null, array_values($currentPermissions));
            }

            return $transferToken;
        });

        AuditLogs::emitAudit($this->strapi, Tokens::AUDITED_EVENTS['TOKEN_CREATE'], [
            'tokenId' => $result['id'] ?? null,
            'name' => $result['name'] ?? null,
            'kind' => 'transfer',
            'description' => $result['description'] ?? null,
            'lifespan' => $result['lifespan'] ?? null,
            'expiresAt' => $result['expiresAt'] ?? null,
            'permissions' => $result['permissions'] ?? [],
        ]);

        return [...$result, 'accessKey' => $accessKey];
    }

    /**
     * Update a token and its permissions
     *
     * @param array<string, mixed> $attributes
     * @return TransferToken
     */
    public function update(mixed $id, array $attributes): array
    {
        // Populated with its permissions: the audit row for this update lists what changed,
        // so the state before the write is needed.
        $originalToken = $this->strapi->db()
            ->query(self::TRANSFER_TOKEN_UID)
            ->findOne(['where' => ['id' => $id], 'populate' => self::POPULATE_FIELDS]);

        if ($originalToken === null) {
            throw new NotFoundError('Token not found');
        }

        $this->assertTokenPermissionsValidity($attributes);
        self::assertValidLifespan($attributes['lifespan'] ?? null);

        $db = $this->strapi->db();
        /** @var TransferToken $updated */
        $updated = $db->transaction(static function () use ($db, $id, $attributes): array {
            $updatedToken = $db->query(self::TRANSFER_TOKEN_UID)->update([
                'select' => self::SELECT_FIELDS,
                'where' => ['id' => $id],
                'data' => array_diff_key($attributes, ['permissions' => true]),
            ]) ?? throw new NotFoundError('Token not found');

            // `if (attributes.permissions)`: an empty array is truthy
            if (is_array($attributes['permissions'] ?? null)) {
                $currentPermissionsResult = $db->query(self::TRANSFER_TOKEN_UID)->load($updatedToken, 'permissions');

                $currentPermissions = array_map(static fn (array $p): mixed => $p['action'] ?? null, is_array($currentPermissionsResult) ? array_values($currentPermissionsResult) : []);
                $newPermissions = self::uniq(is_array($attributes['permissions']) ? array_values($attributes['permissions']) : []);

                $actionsToDelete = array_values(array_filter($currentPermissions, static fn (mixed $a): bool => !in_array($a, $newPermissions, true)));
                $actionsToAdd = array_values(array_filter($newPermissions, static fn (mixed $a): bool => !in_array($a, $currentPermissions, true)));

                // TODO: improve efficiency here
                // method using a loop -- works but very inefficient
                foreach ($actionsToDelete as $action) {
                    $db->query(self::TRANSFER_TOKEN_PERMISSION_UID)->delete([
                        'where' => ['action' => $action, 'token' => $id],
                    ]);
                }

                // TODO: improve efficiency here
                // using a loop -- works but very inefficient
                foreach ($actionsToAdd as $action) {
                    $db->query(self::TRANSFER_TOKEN_PERMISSION_UID)->create([
                        'data' => ['action' => $action, 'token' => $id],
                    ]);
                }
            }

            // retrieve permissions
            $permissionsFromDb = $db->query(self::TRANSFER_TOKEN_UID)->load($updatedToken, 'permissions');

            $result = $updatedToken;
            if (is_array($permissionsFromDb)) {
                $result['permissions'] = array_map(static fn (array $p): mixed => $p['action'] ?? null, array_values($permissionsFromDb));
            } else {
                unset($result['permissions']);
            }

            return $result;
        });

        $changes = Tokens::getTokenChanges(
            [
                'name' => $originalToken['name'] ?? null,
                'description' => $originalToken['description'] ?? null,
                'permissions' => Tokens::toActionRefs(is_array($originalToken['permissions'] ?? null) ? $originalToken['permissions'] : null),
            ],
            ['name' => $updated['name'] ?? null, 'description' => $updated['description'] ?? null, 'permissions' => $updated['permissions'] ?? []],
        );

        if ($changes !== []) {
            AuditLogs::emitAudit($this->strapi, Tokens::AUDITED_EVENTS['TOKEN_UPDATE'], [
                'tokenId' => $originalToken['id'] ?? null,
                'name' => $updated['name'] ?? null,
                'kind' => 'transfer',
                'changes' => $changes,
            ]);
        }

        return $updated;
    }

    /**
     * Revoke (delete) a token
     *
     * @return TransferToken|null
     */
    public function revoke(mixed $id): ?array
    {
        $db = $this->strapi->db();
        /** @var TransferToken|null $deleted */
        $deleted = $db->transaction(static fn (): ?array => $db
            ->query(self::TRANSFER_TOKEN_UID)
            ->delete(['select' => self::SELECT_FIELDS, 'populate' => self::POPULATE_FIELDS, 'where' => ['id' => $id]]));

        if ($deleted !== null) {
            AuditLogs::emitAudit($this->strapi, Tokens::AUDITED_EVENTS['TOKEN_DELETE'], [
                'tokenId' => $deleted['id'] ?? null,
                'name' => $deleted['name'] ?? null,
                'kind' => 'transfer',
            ]);
        }

        return $deleted;
    }

    /**
     *  Get a token
     *
     * @param array<string, mixed> $whereParams
     * @return TransferToken|null
     */
    public function getBy(array $whereParams = []): ?array
    {
        if ($whereParams === []) {
            return null;
        }

        $token = $this->strapi->db()
            ->query(self::TRANSFER_TOKEN_UID)
            ->findOne(['select' => self::SELECT_FIELDS, 'populate' => self::POPULATE_FIELDS, 'where' => $whereParams]);

        if ($token === null) {
            return null;
        }

        return self::flattenTokenPermissions($token);
    }

    /**
     * Retrieve a token by id
     *
     * @return TransferToken|null
     */
    public function getById(mixed $id): ?array
    {
        return $this->getBy(['id' => $id]);
    }

    /**
     * Retrieve a token by name
     *
     * @return TransferToken|null
     */
    public function getByName(mixed $name): ?array
    {
        return $this->getBy(['name' => $name]);
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

    /** @return TransferToken */
    public function regenerate(mixed $id): array
    {
        $accessKey = bin2hex(random_bytes(128));
        $db = $this->strapi->db();
        /** @var TransferToken|null $transferToken */
        $transferToken = $db->transaction(fn (): ?array => $db->query(self::TRANSFER_TOKEN_UID)->update([
            'select' => ['id', 'name', 'accessKey'],
            'where' => ['id' => $id],
            'data' => [
                'accessKey' => $this->hash($accessKey),
            ],
        ]));

        if ($transferToken === null) {
            throw new NotFoundError('The provided token id does not exist');
        }

        AuditLogs::emitAudit($this->strapi, Tokens::AUDITED_EVENTS['TOKEN_REGENERATE'], [
            'tokenId' => $transferToken['id'] ?? null,
            'name' => $transferToken['name'] ?? null,
            'kind' => 'transfer',
        ]);

        return [
            ...$transferToken,
            'accessKey' => $accessKey,
        ];
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
            'lifespan' => $lifespan ?: null,
            'expiresAt' => $lifespan ? (int) floor(microtime(true) * 1000) + (int) $lifespan : null,
        ];
    }

    /**
     * Return a secure sha512 hash of an accessKey
     */
    public function hash(string $accessKey): string
    {
        if (!$this->transfer()->utils->hasValidTokenSalt()) {
            throw new \TypeError('Required token salt is not defined');
        }

        return hash_hmac('sha512', $accessKey, (string) $this->strapi->config()->get('admin.transfer.token.salt'));
    }

    public function checkSaltIsDefined(): void
    {
        // Ignore the check if the data-transfer feature is manually disabled
        if (!$this->strapi->config()->get('server.transfer.remote.enabled')) {
            return;
        }

        if (!$this->transfer()->utils->hasValidTokenSalt()) {
            $this->strapi->log()->warning("Missing transfer.token.salt: Data transfer features have been disabled.\nPlease set transfer.token.salt in config/admin.js (ex: you can generate one using Node with `crypto.randomBytes(16).toString('base64')`)\nFor security reasons, prefer storing the secret in an environment variable and read it in config/admin.js. See https://docs.strapi.io/developer-docs/latest/setup-deployment-guides/configurations/optional/environment.html#configuration-using-environment-variables.");
        }
    }

    /**
     * Flatten a token's database permissions objects to an array of strings
     *
     * @param TransferToken $token
     * @return TransferToken
     */
    private static function flattenTokenPermissions(array $token): array
    {
        if (is_array($token['permissions'] ?? null)) {
            $token['permissions'] = array_map(static fn (mixed $p): mixed => is_array($p) ? ($p['action'] ?? null) : null, array_values($token['permissions']));
        }

        return $token;
    }

    /**
     * Assert that a token's permissions are valid
     *
     * @param array<string, mixed> $attributes
     */
    private function assertTokenPermissionsValidity(array $attributes): void
    {
        $validPermissions = $this->transfer()->permission->providers['action']->keys();
        $permissions = is_array($attributes['permissions'] ?? null) ? array_values($attributes['permissions']) : [];
        $invalidPermissions = array_values(array_filter(
            $permissions,
            static fn (mixed $permission): bool => !in_array($permission, $validPermissions, true),
        ));

        if ($invalidPermissions !== []) {
            throw new ValidationError('Unknown permissions provided: ' . implode(', ', array_map(static fn (mixed $p): string => is_scalar($p) ? (string) $p : (string) json_encode($p), $invalidPermissions)));
        }
    }

    /**
     * Check if a token's lifespan is valid
     */
    private static function isValidLifespan(mixed $lifespan): bool
    {
        if ($lifespan === null) {
            return true;
        }

        if (!is_int($lifespan) && !is_float($lifespan)) {
            return false;
        }

        foreach (Constants::TRANSFER_TOKEN_LIFESPANS as $allowed) {
            if ($allowed !== null && $allowed == $lifespan) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assert that a token's lifespan is valid
     */
    private static function assertValidLifespan(mixed $lifespan): void
    {
        if (!self::isValidLifespan($lifespan)) {
            $values = implode(', ', array_map(static fn (mixed $v): string => $v === null ? '' : (string) $v, array_values(Constants::TRANSFER_TOKEN_LIFESPANS)));
            throw new ValidationError("lifespan must be one of the following values:\n      {$values}");
        }
    }
}
