<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Services;

require_once __DIR__ . '/../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Services\ApiToken;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\NotFoundError;
use Strapi\Utils\Errors\ValidationError;

/**
 * Port of server/src/services/__tests__/api-token.test.ts (and services-api-token-alias.test.ts).
 * Upstream mocks `strapi.db`; here the service runs against a booted app and the assertions read
 * the rows it wrote and the audit events it emitted.
 */
final class ApiTokenTest extends TestCase
{
    private static ?Strapi $strapi = null;

    /** @var list<array{0: string, 1: mixed}> */
    private static array $events = [];

    private static int $counter = 0;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedAdminApp::boot();
        self::$strapi->config()->set('admin.secrets.encryptionKey', 'test-encryption-key');
        foreach (['token.create', 'token.update', 'token.delete', 'token.regenerate'] as $event) {
            self::$strapi->eventHub()->on($event, static function (mixed $payload) use ($event): void {
                self::$events[] = [$event, $payload];
            });
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    protected function setUp(): void
    {
        self::$events = [];
    }

    private static function strapi(): Strapi
    {
        return self::$strapi ?? throw new \LogicException('not booted');
    }

    private static function contentApi(): ApiToken
    {
        return self::strapi()->service('admin::api-token-content-api');
    }

    private static function admin(): ApiToken
    {
        return self::strapi()->service('admin::api-token-admin');
    }

    private static function tokenName(): string
    {
        return 'token_' . ++self::$counter;
    }

    /** @return list<string> */
    private static function validContentApiActions(): array
    {
        return self::strapi()->contentAPI()->permissions->providers['action']->keys();
    }

    /**
     * A non-super-admin user whose role holds the given permissions.
     *
     * @param list<array<string, mixed>> $permissions
     * @return array<string, mixed>
     */
    private static function userWithPermissions(array $permissions): array
    {
        $n = ++self::$counter;
        $role = self::strapi()->service('admin::role')->create(['name' => "role {$n}", 'description' => '']);
        self::strapi()->service('admin::role')->assignPermissions($role['id'], $permissions);

        return BootedAdminApp::createUser(self::strapi(), ['email' => "user{$n}@test.com", 'roles' => [$role['id']]]);
    }

    /** @return array<string, mixed> */
    private static function superAdmin(): array
    {
        $n = ++self::$counter;

        return BootedAdminApp::createUser(self::strapi(), ['email' => "super{$n}@test.com"]);
    }

    public function testTheDeprecatedApiTokenServiceIsTheContentApiService(): void
    {
        $alias = self::strapi()->service('admin::api-token');

        self::assertInstanceOf(ApiToken::class, $alias);
        self::assertSame('content-api', $alias->kind);
    }

    // create

    public function testCreatesANewReadOnlyToken(): void
    {
        $name = self::tokenName();
        $res = self::contentApi()->create(['name' => $name, 'description' => 'api-token_tests-description', 'type' => 'read-only']);

        self::assertSame(256, strlen($res['accessKey']));
        self::assertSame($name, $res['name']);
        self::assertSame('content-api', $res['kind']);
        self::assertSame('read-only', $res['type']);
        self::assertNull($res['lifespan']);
        self::assertNull($res['expiresAt']);
        self::assertArrayNotHasKey('adminPermissions', $res);
        self::assertArrayNotHasKey('adminUserOwner', $res);

        $row = self::strapi()->db()->query('admin::api-token')->findOne(['where' => ['id' => $res['id']]]);
        self::assertSame(self::contentApi()->hash($res['accessKey']), $row['accessKey']);
        self::assertSame(hash_hmac('sha512', $res['accessKey'], (string) self::strapi()->config()->get('admin.apiToken.salt')), $row['accessKey']);
        self::assertSame($res['accessKey'], self::strapi()->service('admin::encryption')->decrypt($row['encryptedKey']));

        self::assertSame('token.create', self::$events[0][0]);
        self::assertSame(['tokenId' => $res['id'], 'name' => $name, 'kind' => 'content-api', 'description' => 'api-token_tests-description', 'lifespan' => null, 'expiresAt' => null, 'type' => 'read-only'], self::$events[0][1]);
    }

    public function testCreatesANewTokenWithLifespan(): void
    {
        $now = (int) (microtime(true) * 1000);
        $lifespan = 7 * 24 * 3600 * 1000;
        $res = self::contentApi()->create(['name' => self::tokenName(), 'description' => '', 'type' => 'read-only', 'lifespan' => $lifespan]);

        self::assertSame((string) $lifespan, $res['lifespan']);
        $expiresAt = strtotime((string) $res['expiresAt']) * 1000;
        self::assertEqualsWithDelta($now + $lifespan, $expiresAt, 3000);
    }

    public function testItThrowsWhenCreatingATokenWithInvalidLifespan(): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessageMatches('/lifespan must be one of the following values/');

        self::contentApi()->create(['name' => self::tokenName(), 'description' => '', 'type' => 'read-only', 'lifespan' => 1234]);
    }

    public function testCreatesACustomTokenWithDuplicatePermissionsIgnoringDuplicates(): void
    {
        $actions = self::validContentApiActions();
        $res = self::contentApi()->create(['name' => self::tokenName(), 'description' => '', 'type' => 'custom', 'permissions' => [$actions[0], $actions[0], $actions[1]]]);

        self::assertSame([$actions[0], $actions[1]], $res['permissions']);
        self::assertSame([$actions[0], $actions[1]], self::$events[0][1]['permissions']);
    }

    public function testCreatesACustomTokenWithNoPermissions(): void
    {
        $res = self::contentApi()->create(['name' => self::tokenName(), 'description' => '', 'type' => 'custom', 'permissions' => []]);

        self::assertSame([], $res['permissions']);
    }

    public function testCreatesACustomTokenWithInvalidPermissionsShouldThrow(): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Unknown permissions provided: api::foo.foo.unknown');

        self::contentApi()->create(['name' => self::tokenName(), 'description' => '', 'type' => 'custom', 'permissions' => [self::validContentApiActions()[0], 'api::foo.foo.unknown']]);
    }

    public function testThrowsWhenCreatingAContentApiTokenWithAdminPermissions(): void
    {
        $this->expectExceptionObject(new ValidationError('Legacy tokens cannot carry admin permissions'));

        self::contentApi()->create(['name' => self::tokenName(), 'type' => 'read-only', 'adminPermissions' => [['action' => 'admin::users.read']]]);
    }

    public function testThrowsWhenCreatingAContentApiTokenWithAdminUserOwner(): void
    {
        $this->expectExceptionObject(new ValidationError('Legacy tokens cannot have an admin user owner'));

        self::contentApi()->create(['name' => self::tokenName(), 'type' => 'read-only', 'adminUserOwner' => 1]);
    }

    public function testThrowsWhenCreatingAnAdminTokenWithAContentApiType(): void
    {
        $this->expectExceptionObject(new ValidationError('Admin tokens cannot carry a legacy type (custom/read-only/full-access)'));

        self::admin()->create(['name' => self::tokenName(), 'type' => 'read-only'], self::superAdmin());
    }

    public function testThrowsWhenCreatingAnAdminTokenWithContentApiPermissions(): void
    {
        $this->expectExceptionObject(new ValidationError('Admin tokens cannot carry legacy content-API permissions'));

        self::admin()->create(['name' => self::tokenName(), 'permissions' => ['api::a.a.find']], self::superAdmin());
    }

    public function testThrowsWhenCreatingAnAdminTokenWithoutACallingUser(): void
    {
        $this->expectExceptionObject(new ValidationError('Creating an admin token requires an authenticated admin user'));

        self::admin()->create(['name' => self::tokenName()]);
    }

    public function testCreatesAnAdminTokenAndDefaultsOwnerToCallingUser(): void
    {
        $user = self::superAdmin();
        $res = self::admin()->create(['name' => self::tokenName(), 'description' => ''], $user);

        self::assertSame('admin', $res['kind']);
        self::assertSame([
            'id' => $user['id'],
            'firstname' => $user['firstname'],
            'lastname' => $user['lastname'],
            'username' => $user['username'] ?? null,
            'email' => $user['email'],
        ], $res['adminUserOwner']);
        self::assertArrayNotHasKey('permissions', $res);
        self::assertSame($user['id'], self::$events[0][1]['adminUserOwner']);
    }

    public function testRejectsAnAdminUserOwnerThatIsNotTheCaller(): void
    {
        $user = self::superAdmin();
        $other = self::superAdmin();

        $this->expectExceptionObject(new ValidationError('adminUserOwner must match the authenticated admin user'));

        self::admin()->create(['name' => self::tokenName(), 'adminUserOwner' => $other['id']], $user);
    }

    // checkSaltIsDefined

    public function testItDoesNothingIfTheSaltIsAlreadyDefined(): void
    {
        self::contentApi()->checkSaltIsDefined();
        self::addToAssertionCount(1);
    }

    public function testItThrowsIfTheSaltIsNotDefined(): void
    {
        $salt = self::strapi()->config()->get('admin.apiToken.salt');
        $env = getenv('API_TOKEN_SALT');
        putenv('API_TOKEN_SALT');
        self::strapi()->config()->set('admin.apiToken.salt', null);

        try {
            self::contentApi()->checkSaltIsDefined();
            self::fail('expected an exception');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('Missing apiToken.salt.', $e->getMessage());
        } finally {
            self::strapi()->config()->set('admin.apiToken.salt', $salt);
            if (is_string($env)) {
                putenv("API_TOKEN_SALT={$env}");
            }
        }
    }

    // list

    public function testSuperAdminSeesAllTokensAndNonSuperAdminOnlyOwnerlessAndOwnTokens(): void
    {
        self::strapi()->db()->query('admin::api-token')->deleteMany([]);
        $super = self::superAdmin();
        $editor = self::userWithPermissions([['action' => 'admin::users.read']]);

        $mine = self::admin()->create(['name' => 'b-mine', 'description' => ''], $editor);
        $theirs = self::admin()->create(['name' => 'a-theirs', 'description' => ''], $super);
        $content = self::contentApi()->create(['name' => 'c-content', 'description' => '', 'type' => 'read-only']);

        self::assertSame(['a-theirs', 'b-mine'], array_column(self::admin()->list($super), 'name'));
        self::assertSame(['b-mine'], array_column(self::admin()->list($editor), 'name'));
        self::assertSame(['c-content'], array_column(self::contentApi()->list($editor), 'name'));

        $listed = self::admin()->list($super)[0];
        self::assertSame($theirs['id'], $listed['id']);
        self::assertSame(['id', 'firstname', 'lastname', 'username', 'email'], array_keys($listed['adminUserOwner']));
        self::assertArrayNotHasKey('permissions', $listed);

        $listedContent = self::contentApi()->list($super)[0];
        self::assertSame($content['id'], $listedContent['id']);
        self::assertSame('content-api', $listedContent['kind']);
        self::assertSame([], $listedContent['permissions']);
        self::assertArrayNotHasKey('adminUserOwner', $listedContent);
        self::assertSame($mine['id'], self::admin()->list($editor)[0]['id']);
    }

    // revoke

    public function testItDeletesTheToken(): void
    {
        $token = self::contentApi()->create(['name' => self::tokenName(), 'description' => '', 'type' => 'read-only']);
        self::$events = [];

        $res = self::contentApi()->revoke($token['id']);

        self::assertSame($token['id'], $res['id']);
        self::assertSame('content-api', $res['kind']);
        self::assertSame([], $res['permissions']);
        self::assertArrayNotHasKey('adminPermissions', $res);
        self::assertArrayNotHasKey('adminUserOwner', $res);
        self::assertNull(self::strapi()->db()->query('admin::api-token')->findOne(['where' => ['id' => $token['id']]]));
        self::assertSame(['token.delete', ['tokenId' => $token['id'], 'name' => $token['name'], 'kind' => 'content-api']], self::$events[0]);
    }

    public function testRevokeReturnsNullIfTheResourceDoesNotExist(): void
    {
        self::assertNull(self::contentApi()->revoke(424242));
    }

    public function testItDeletesAdminPermissionsBeforeTheTokenForAdminTokens(): void
    {
        $user = self::superAdmin();
        $token = self::admin()->create(['name' => self::tokenName(), 'description' => '', 'adminPermissions' => [['action' => 'admin::users.read']]], $user);
        $permissionIds = array_column($token['adminPermissions'], 'id');
        self::assertCount(1, $permissionIds);
        self::$events = [];

        $res = self::admin()->revoke($token['id']);

        self::assertSame('admin', $res['kind']);
        self::assertSame($user['id'], $res['adminUserOwner']['id']);
        self::assertSame(0, self::strapi()->db()->query('admin::permission')->count(['where' => ['id' => ['$in' => $permissionIds]]]));
        self::assertSame($user['id'], self::$events[0][1]['adminUserOwner']);
    }

    public function testRevokeNormalisesKindNullToContentApi(): void
    {
        $token = self::contentApi()->create(['name' => self::tokenName(), 'description' => '', 'type' => 'read-only']);
        self::strapi()->db()->sql()->from('strapi_api_tokens')->where(['id' => $token['id']])->update(['kind' => null])->run();

        self::assertSame('content-api', self::contentApi()->revoke($token['id'])['kind']);
    }

    // getById / getByName / getBy

    public function testGetByIdRetrievesTheTokenAndReturnsNullIfTheResourceDoesNotExist(): void
    {
        $token = self::contentApi()->create(['name' => self::tokenName(), 'description' => '', 'type' => 'read-only']);

        $res = self::contentApi()->getById($token['id']);
        self::assertSame($token['id'], $res['id']);
        self::assertSame('content-api', $res['kind']);
        self::assertArrayNotHasKey('accessKey', $res);
        self::assertNull(self::contentApi()->getById(424242));
        self::assertNull(self::admin()->getById($token['id']), 'the admin service only sees admin tokens');
    }

    public function testGetByIdAndGetByNameRetrieveLegacyTokensWhoseDbKindIsNull(): void
    {
        $token = self::contentApi()->create(['name' => self::tokenName(), 'description' => '', 'type' => 'read-only']);
        self::strapi()->db()->sql()->from('strapi_api_tokens')->where(['id' => $token['id']])->update(['kind' => null])->run();

        self::assertSame('content-api', self::contentApi()->getById($token['id'])['kind']);
        self::assertSame($token['id'], self::contentApi()->getByName($token['name'])['id']);
    }

    public function testGetByWithIncludeDecryptedKeyReturnsThePlaintextAccessKey(): void
    {
        $token = self::contentApi()->create(['name' => self::tokenName(), 'description' => '', 'type' => 'read-only']);

        self::assertArrayNotHasKey('accessKey', self::contentApi()->getBy(['id' => $token['id']]));
        self::assertSame($token['accessKey'], self::contentApi()->getBy(['id' => $token['id']], ['includeDecryptedKey' => true])['accessKey']);

        self::strapi()->db()->query('admin::api-token')->update(['where' => ['id' => $token['id']], 'data' => ['encryptedKey' => null]]);
        self::assertArrayNotHasKey('accessKey', self::contentApi()->getBy(['id' => $token['id']], ['includeDecryptedKey' => true]));
        self::assertNull(self::contentApi()->getBy([]));
    }

    // regenerate

    public function testItRegeneratesTheAccessKey(): void
    {
        $token = self::contentApi()->create(['name' => self::tokenName(), 'description' => '', 'type' => 'read-only']);
        self::$events = [];

        $res = self::contentApi()->regenerate($token['id']);

        self::assertSame(['id', 'name', 'accessKey', 'kind'], array_keys($res));
        self::assertSame('content-api', $res['kind']);
        self::assertNotSame($token['accessKey'], $res['accessKey']);
        self::assertSame($res['id'], self::contentApi()->getByAccessKey(self::contentApi()->hash($res['accessKey']))['id']);
        self::assertSame(['token.regenerate', ['tokenId' => $token['id'], 'name' => $token['name'], 'kind' => 'content-api']], self::$events[0]);
    }

    public function testRegenerateThrowsANotFoundIfTheIdIsNotFound(): void
    {
        $this->expectExceptionObject(new NotFoundError('The provided token id does not exist'));

        self::contentApi()->regenerate(424242);
    }

    public function testRegenerateRecordsTheOwnerIdOfAnAdminTokenAndStripsItFromTheResponse(): void
    {
        $user = self::superAdmin();
        $token = self::admin()->create(['name' => self::tokenName(), 'description' => ''], $user);
        self::$events = [];

        $res = self::admin()->regenerate($token['id']);

        self::assertArrayNotHasKey('adminUserOwner', $res);
        self::assertSame('admin', $res['kind']);
        self::assertSame($user['id'], self::$events[0][1]['adminUserOwner']);
    }

    // update

    public function testUpdatesANonCustomTokenAndEmitsTheChanges(): void
    {
        $token = self::contentApi()->create(['name' => self::tokenName(), 'description' => 'before', 'type' => 'read-only']);
        self::$events = [];

        $res = self::contentApi()->update($token['id'], ['name' => 'renamed ' . $token['id'], 'description' => 'after', 'type' => 'full-access', 'lifespan' => 7 * 24 * 3600 * 1000]);

        self::assertSame('renamed ' . $token['id'], $res['name']);
        self::assertSame('full-access', $res['type']);
        self::assertSame([], $res['permissions']);
        self::assertNull($res['lifespan'], 'lifespan and expiresAt are immutable after creation');
        self::assertSame('token.update', self::$events[0][0]);
        self::assertSame([
            'name' => ['before' => $token['name'], 'after' => 'renamed ' . $token['id']],
            'description' => ['before' => 'before', 'after' => 'after'],
            'type' => ['before' => 'read-only', 'after' => 'full-access'],
        ], self::$events[0][1]['changes']);
    }

    public function testDoesNotEmitTokenUpdateWhenNothingChanged(): void
    {
        $token = self::contentApi()->create(['name' => self::tokenName(), 'description' => 'same', 'type' => 'read-only']);
        self::$events = [];

        self::contentApi()->update($token['id'], ['name' => $token['name'], 'description' => 'same']);

        self::assertSame([], self::$events);
    }

    public function testRejectsSwitchingToCustomWithoutPermissionsInTheBody(): void
    {
        $token = self::contentApi()->create(['name' => self::tokenName(), 'description' => '', 'type' => 'read-only']);

        $this->expectExceptionObject(new ValidationError('Missing permissions attribute for custom token'));

        self::contentApi()->update($token['id'], ['type' => 'custom']);
    }

    public function testSwitchesACustomTokenToReadOnlyWithPermissionsNull(): void
    {
        $actions = self::validContentApiActions();
        $token = self::contentApi()->create(['name' => self::tokenName(), 'description' => '', 'type' => 'custom', 'permissions' => [$actions[0]]]);

        $res = self::contentApi()->update($token['id'], ['type' => 'read-only', 'permissions' => null]);

        self::assertSame('read-only', $res['type']);
        self::assertSame([], $res['permissions']);
    }

    public function testRejectsPermissionsNullOnACustomToken(): void
    {
        $token = self::contentApi()->create(['name' => self::tokenName(), 'description' => '', 'type' => 'custom', 'permissions' => [self::validContentApiActions()[0]]]);

        $this->expectExceptionObject(new ValidationError('Missing permissions attribute for custom token'));

        self::contentApi()->update($token['id'], ['permissions' => null]);
    }

    public function testUpdatesTheActionsOfACustomToken(): void
    {
        $actions = self::validContentApiActions();
        $token = self::contentApi()->create(['name' => self::tokenName(), 'description' => '', 'type' => 'custom', 'permissions' => [$actions[0], $actions[1]]]);
        self::$events = [];

        $res = self::contentApi()->update($token['id'], ['permissions' => [$actions[1], $actions[2], $actions[2]]]);

        self::assertEqualsCanonicalizing([$actions[1], $actions[2]], $res['permissions']);
        self::assertSame(['permissions'], array_keys(self::$events[0][1]['changes']));
    }

    public function testThrowsWhenTryingToChangeKindOnUpdate(): void
    {
        $token = self::contentApi()->create(['name' => self::tokenName(), 'description' => '', 'type' => 'read-only']);

        $this->expectExceptionObject(new ValidationError('kind is immutable after creation'));

        self::contentApi()->update($token['id'], ['kind' => 'admin']);
    }

    public function testUpdatesALegacyTokenWithNullKindAndMigratesItsKind(): void
    {
        $token = self::contentApi()->create(['name' => self::tokenName(), 'description' => '', 'type' => 'read-only']);
        self::strapi()->db()->sql()->from('strapi_api_tokens')->where(['id' => $token['id']])->update(['kind' => null])->run();

        // a client echoing back the normalised kind is not rejected
        self::contentApi()->update($token['id'], ['kind' => 'content-api', 'description' => 'migrated']);

        $row = self::strapi()->db()->query('admin::api-token')->findOne(['where' => ['id' => $token['id']]]);
        self::assertSame('content-api', $row['kind']);
    }

    public function testThrowsWhenSettingAdminFieldsOnAContentApiTokenUpdateAndTheReverse(): void
    {
        $content = self::contentApi()->create(['name' => self::tokenName(), 'description' => '', 'type' => 'read-only']);
        $admin = self::admin()->create(['name' => self::tokenName(), 'description' => ''], self::superAdmin());

        try {
            self::contentApi()->update($content['id'], ['adminPermissions' => []]);
            self::fail('expected a ValidationError');
        } catch (ValidationError $e) {
            self::assertSame('Legacy tokens cannot carry admin permissions', $e->getMessage());
        }

        $this->expectExceptionObject(new ValidationError('Admin tokens cannot carry a legacy type (custom/read-only/full-access)'));
        self::admin()->update($admin['id'], ['type' => 'custom']);
    }

    public function testUpdateThrowsNotFoundForAMissingToken(): void
    {
        $this->expectExceptionObject(new NotFoundError('Token not found'));

        self::contentApi()->update(424242, ['name' => 'x']);
    }

    // admin permission ceiling

    public function testEnforcesTheOwnerCeilingWhenCreatingAndUpdatingAdminTokenPermissions(): void
    {
        $editor = self::userWithPermissions([['action' => 'admin::users.read'], ['action' => 'admin::roles.read']]);

        $token = self::admin()->create(['name' => self::tokenName(), 'description' => '', 'adminPermissions' => [['action' => 'admin::users.read']]], $editor);
        self::assertSame(['admin::users.read'], array_column($token['adminPermissions'], 'action'));

        try {
            self::admin()->create(['name' => self::tokenName(), 'adminPermissions' => [['action' => 'admin::users.delete']]], $editor);
            self::fail('expected a ValidationError');
        } catch (ValidationError $e) {
            self::assertSame('Cannot assign admin permissions that exceed your own. Exceeding: admin::users.delete', $e->getMessage());
        }

        $updated = self::admin()->update($token['id'], ['adminPermissions' => [['action' => 'admin::roles.read']]]);
        self::assertSame(['admin::roles.read'], array_column($updated['adminPermissions'], 'action'));
        self::assertSame($editor['id'], $updated['adminUserOwner']['id']);

        $this->expectExceptionObject(new ValidationError('Cannot assign admin permissions that exceed your own. Exceeding: admin::users.create'));
        self::admin()->update($token['id'], ['adminPermissions' => [['action' => 'admin::users.create']]]);
    }

    public function testRejectsUnknownAdminActions(): void
    {
        $this->expectExceptionObject(new ValidationError('Unknown admin action: admin::nope'));

        self::admin()->create(['name' => self::tokenName(), 'adminPermissions' => [['action' => 'admin::nope']]], self::superAdmin());
    }

    public function testAdminUserOwnerCannotBeChangedOnUpdate(): void
    {
        $owner = self::superAdmin();
        $token = self::admin()->create(['name' => self::tokenName(), 'description' => ''], $owner);

        self::admin()->update($token['id'], ['adminUserOwner' => $owner['id']]);

        $this->expectExceptionObject(new ValidationError('adminUserOwner cannot be changed on update'));
        self::admin()->update($token['id'], ['adminUserOwner' => $owner['id'] + 1000]);
    }

    public function testStripsUnregisteredConditionsFromSuperAdminPermissions(): void
    {
        $result = self::admin()->enforceAdminPermissionsCeiling(self::superAdmin(), [
            ['action' => 'admin::users.read', 'conditions' => ['admin::is-creator', 'unknown::condition']],
        ]);

        self::assertSame([['action' => 'admin::users.read', 'conditions' => ['admin::is-creator']]], $result);
        self::assertSame([], self::admin()->enforceAdminPermissionsCeiling(self::superAdmin(), []));
    }

    public function testRejectsWhenOwnerIsFieldRestrictedButRequestOmitsPropertiesFields(): void
    {
        $editor = self::userWithPermissions([
            ['action' => 'plugin::content-manager.explorer.read', 'subject' => 'api::article.article', 'properties' => ['fields' => ['title']]],
        ]);

        try {
            self::admin()->enforceAdminPermissionsCeiling($editor, [['action' => 'plugin::content-manager.explorer.read', 'subject' => 'api::article.article']]);
            self::fail('expected a ValidationError');
        } catch (ValidationError $e) {
            self::assertSame('Cannot assign admin permissions that exceed your own. Exceeding: plugin::content-manager.explorer.read on api::article.article (fields are required due to owner field restrictions)', $e->getMessage());
        }

        $clamped = self::admin()->enforceAdminPermissionsCeiling($editor, [['action' => 'plugin::content-manager.explorer.read', 'subject' => 'api::article.article', 'properties' => ['fields' => ['title']]]]);
        self::assertSame([], $clamped[0]['conditions']);
    }

    // reconcileTokenPermissionsToUserCeiling

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function perm(array $overrides = []): array
    {
        return ['id' => 1, 'action' => 'plugin::content-manager.explorer.read', 'subject' => 'api::article.article', 'properties' => [], 'conditions' => [], ...$overrides];
    }

    public function testReconcileKeepsPermissionUnchangedWhenActionSubjectMatchAndConditionsAreIdentical(): void
    {
        self::assertSame(['toDelete' => [], 'toUpdate' => []], self::admin()->reconcileTokenPermissionsToUserCeiling([self::perm()], [self::perm()]));
    }

    public function testReconcileMovesPermissionToToDeleteWhenNoMatchingUserPermissionExists(): void
    {
        $token = self::perm(['action' => 'plugin::content-manager.explorer.delete']);

        self::assertSame(['toDelete' => [$token], 'toUpdate' => []], self::admin()->reconcileTokenPermissionsToUserCeiling([self::perm()], [$token]));
    }

    public function testReconcileMovesPermissionToToDeleteWhenTokenFieldsExceedUserAllowedFields(): void
    {
        $token = self::perm(['properties' => ['fields' => ['title', 'body']]]);

        self::assertSame(['toDelete' => [$token], 'toUpdate' => []], self::admin()->reconcileTokenPermissionsToUserCeiling([self::perm(['properties' => ['fields' => ['title']]])], [$token]));
        self::assertSame(['toDelete' => [], 'toUpdate' => []], self::admin()->reconcileTokenPermissionsToUserCeiling([self::perm()], [$token]), 'a user permission without fields allows all fields');
    }

    public function testReconcileComputesTheUnionOfConditionsAndClearsThemWhenOneUserPermissionIsUnconditional(): void
    {
        $token = self::perm(['id' => 7]);

        self::assertSame(
            ['toDelete' => [], 'toUpdate' => [['id' => 7, 'conditions' => ['admin::is-creator', 'admin::has-same-role-as-creator']]]],
            self::admin()->reconcileTokenPermissionsToUserCeiling([
                self::perm(['conditions' => ['admin::is-creator']]),
                self::perm(['conditions' => ['admin::has-same-role-as-creator', 'admin::is-creator']]),
            ], [$token]),
        );
        self::assertSame(
            ['toDelete' => [], 'toUpdate' => [['id' => 7, 'conditions' => []]]],
            self::admin()->reconcileTokenPermissionsToUserCeiling([self::perm(['conditions' => ['admin::is-creator']]), self::perm()], [[...$token, 'conditions' => ['admin::is-creator']]]),
        );
        self::assertSame(
            ['toDelete' => [], 'toUpdate' => []],
            self::admin()->reconcileTokenPermissionsToUserCeiling([self::perm(['conditions' => ['b', 'a']])], [[...$token, 'conditions' => ['a', 'b']]]),
            'the same set in another order is unchanged',
        );
    }

    public function testReconcileTreatsNullAndMissingSubjectAsEquivalent(): void
    {
        $user = self::perm(['action' => 'admin::users.read', 'subject' => null]);
        $token = self::perm(['action' => 'admin::users.read']);
        unset($token['subject']);

        self::assertSame(['toDelete' => [], 'toUpdate' => []], self::admin()->reconcileTokenPermissionsToUserCeiling([$user], [$token]));
    }

    // syncApiTokenPermissionsForUser

    public function testSyncDeletesTokenPermissionsThatAreNoLongerInTheUserScope(): void
    {
        $editor = self::userWithPermissions([['action' => 'admin::users.read'], ['action' => 'admin::roles.read']]);
        $token = self::admin()->create(['name' => self::tokenName(), 'description' => '', 'adminPermissions' => [['action' => 'admin::users.read'], ['action' => 'admin::roles.read']]], $editor);

        $roleId = $editor['roles'][0]['id'];
        self::strapi()->service('admin::role')->assignPermissions($roleId, [['action' => 'admin::users.read']]);

        $remaining = self::strapi()->db()->query('admin::permission')->findMany(['where' => ['apiToken' => ['id' => $token['id']]]]);
        self::assertSame(['admin::users.read'], array_column($remaining, 'action'));
    }

    public function testSyncSkipsSuperAdminsAndMissingUsers(): void
    {
        $super = self::superAdmin();
        $token = self::admin()->create(['name' => self::tokenName(), 'description' => '', 'adminPermissions' => [['action' => 'admin::users.read']]], $super);

        self::admin()->syncPermissionsForUser($super['id']);
        self::admin()->syncPermissionsForUser(424242);

        self::assertSame(1, self::strapi()->db()->query('admin::permission')->count(['where' => ['apiToken' => ['id' => $token['id']]]]));
    }

    public function testDeletingTheOwnerDeletesItsAdminTokens(): void
    {
        $owner = self::superAdmin();
        $token = self::admin()->create(['name' => self::tokenName(), 'description' => '', 'adminPermissions' => [['action' => 'admin::users.read']]], $owner);
        $permissionId = $token['adminPermissions'][0]['id'];

        self::strapi()->db()->query('admin::user')->delete(['where' => ['id' => $owner['id']]]);

        self::assertNull(self::strapi()->db()->query('admin::api-token')->findOne(['where' => ['id' => $token['id']]]));
        self::assertNull(self::strapi()->db()->query('admin::permission')->findOne(['where' => ['id' => $permissionId]]));
    }

    public function testListDoesNotThrowWhenAnAdminTokenHasNoOwner(): void
    {
        $owner = self::superAdmin();
        $token = self::admin()->create(['name' => self::tokenName(), 'description' => ''], $owner);
        // an orphaned row: the owner link removed without the lifecycle that deletes the tokens
        self::strapi()->db()->query('admin::api-token')->update(['where' => ['id' => $token['id']], 'data' => ['adminUserOwner' => null]]);

        $listed = array_values(array_filter(self::admin()->list($owner), static fn (array $t): bool => $t['id'] === $token['id']));

        self::assertCount(1, $listed);
        self::assertNull($listed[0]['adminUserOwner']);
    }
}
