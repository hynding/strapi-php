<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Strategies;

require_once __DIR__ . '/../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Strategies\ContentApiToken;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ForbiddenError;
use Strapi\Utils\Errors\UnauthorizedError;

/**
 * Port of server/src/strategies/__tests__/content-api-token.test.ts, against real tokens of a
 * booted app instead of a mocked `admin::api-token` service.
 */
final class ContentApiTokenTest extends TestCase
{
    private static ?Strapi $strapi = null;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedAdminApp::boot();
        self::$strapi->config()->set('admin.secrets.encryptionKey', 'test-encryption-key');
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    private static function strapi(): Strapi
    {
        return self::$strapi ?? throw new \LogicException('not booted');
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private static function createToken(array $attributes = []): array
    {
        static $i = 0;
        $i++;

        return self::strapi()->service('admin::api-token-content-api')->create([
            'name' => "api-token_tests-{$i}",
            'description' => 'api-token_tests-description',
            'type' => 'read-only',
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private static function authenticate(?string $authorization): array
    {
        $ctx = BootedAdminApp::ctx('GET', '/api/articles', null, $authorization === null ? [] : ['Authorization' => $authorization]);

        return ContentApiToken::authenticate($ctx, self::strapi());
    }

    public function testAuthenticatesAValidHashedAccessKey(): void
    {
        $token = self::createToken();

        $response = self::authenticate("bearer {$token['accessKey']}");

        self::assertTrue($response['authenticated']);
        self::assertSame($token['id'], $response['credentials']['id']);
        self::assertSame('content-api', $response['credentials']['kind']);
        self::assertSame('read-only', $response['credentials']['type']);
    }

    public function testUpdatesLastUsedAtIfTheTokenHasNotBeenUsedInTheLastHour(): void
    {
        $token = self::createToken();
        $twoHoursAgo = (new \DateTimeImmutable('-2 hours'));
        self::strapi()->db()->query('admin::api-token')->update(['where' => ['id' => $token['id']], 'data' => ['lastUsedAt' => $twoHoursAgo]]);

        self::authenticate("bearer {$token['accessKey']}");

        $row = self::strapi()->db()->query('admin::api-token')->findOne(['where' => ['id' => $token['id']]]);
        self::assertGreaterThan($twoHoursAgo->getTimestamp() + 3600, strtotime((string) $row['lastUsedAt']));
    }

    public function testDoesNotUpdateLastUsedAtIfTheTokenHasBeenUsedInTheLastHour(): void
    {
        $token = self::createToken();
        $tenMinutesAgo = new \DateTimeImmutable('-10 minutes');
        self::strapi()->db()->query('admin::api-token')->update(['where' => ['id' => $token['id']], 'data' => ['lastUsedAt' => $tenMinutesAgo]]);
        $before = self::strapi()->db()->query('admin::api-token')->findOne(['where' => ['id' => $token['id']]]);

        self::authenticate("bearer {$token['accessKey']}");

        $after = self::strapi()->db()->query('admin::api-token')->findOne(['where' => ['id' => $token['id']]]);
        self::assertSame($before['lastUsedAt'], $after['lastUsedAt']);
    }

    public function testFailsToAuthenticateIfTheAuthorizationHeaderIsMissing(): void
    {
        self::assertSame(['authenticated' => false], self::authenticate(null));
    }

    public function testFailsToAuthenticateAnInvalidAuthorizationHeader(): void
    {
        self::assertSame(['authenticated' => false], self::authenticate('invalid-header'));
    }

    public function testFailsToAuthenticateAnInvalidBearerToken(): void
    {
        self::assertSame(['authenticated' => false], self::authenticate('bearer invalid-token'));
    }

    public function testExpiredTokenReturnsAuthenticatedFalseWithUnauthorizedError(): void
    {
        $token = self::createToken();
        self::strapi()->db()->query('admin::api-token')->update(['where' => ['id' => $token['id']], 'data' => ['expiresAt' => new \DateTimeImmutable('-1 minute')]]);

        $response = self::authenticate("bearer {$token['accessKey']}");

        self::assertFalse($response['authenticated']);
        self::assertInstanceOf(UnauthorizedError::class, $response['error']);
        self::assertSame('Token expired', $response['error']->getMessage());
    }

    public function testRejectsAnAdminTokenOnTheContentApi(): void
    {
        $owner = BootedAdminApp::createUser(self::strapi(), ['email' => 'kind-check@test.com']);
        $token = self::strapi()->service('admin::api-token-admin')->create(['name' => 'admin-kind-check', 'description' => ''], $owner);

        self::assertSame(['authenticated' => false], self::authenticate("bearer {$token['accessKey']}"));
    }

    public function testAuthenticatesALegacyTokenWithKindNull(): void
    {
        $token = self::createToken();
        self::strapi()->db()->sql()->from('strapi_api_tokens')->where(['id' => $token['id']])->update(['kind' => null])->run();

        $response = self::authenticate("bearer {$token['accessKey']}");

        self::assertTrue($response['authenticated']);
        self::assertSame('content-api', $response['credentials']['kind']);
    }

    public function testCustomTokensGetAnAbilityFromTheirPermissions(): void
    {
        $actions = self::strapi()->contentAPI()->permissions->providers['action']->keys();
        self::assertNotEmpty($actions);
        $token = self::createToken(['type' => 'custom', 'permissions' => [$actions[0]]]);

        $response = self::authenticate("bearer {$token['accessKey']}");

        self::assertTrue($response['authenticated']);
        self::assertTrue($response['ability']->can($actions[0]));
        self::assertNull(ContentApiToken::verify($response, ['scope' => [$actions[0]]]));
    }

    // Verify an access key

    private const READ_ONLY = ['id' => 1, 'kind' => 'content-api', 'name' => 'api-token_tests-name', 'description' => 'api-token_tests-description', 'type' => 'read-only'];

    private static function customAbility(): object
    {
        return new class () {
            public function can(string $action): bool
            {
                return in_array($action, ['api::model.model.update', 'api::model.model.read'], true);
            }
        };
    }

    public function testVerifyReadOnlyAccess(): void
    {
        self::assertNull(ContentApiToken::verify(['credentials' => self::READ_ONLY], ['scope' => ['api::model.model.find']]));
    }

    public function testVerifyFullAccessAccess(): void
    {
        self::assertNull(ContentApiToken::verify(['credentials' => [...self::READ_ONLY, 'type' => 'full-access']], ['scope' => ['api::model.model.create']]));
    }

    public function testVerifyCustomAccess(): void
    {
        self::assertNull(ContentApiToken::verify(
            ['credentials' => [...self::READ_ONLY, 'type' => 'custom'], 'ability' => self::customAbility()],
            ['scope' => ['api::model.model.update']],
        ));
    }

    public function testVerifyWithExpirationInFuture(): void
    {
        self::assertNull(ContentApiToken::verify(
            ['credentials' => [...self::READ_ONLY, 'expiresAt' => (int) (microtime(true) * 1000) + 99999]],
            ['scope' => ['api::model.model.find']],
        ));
    }

    public function testThrowsWithExpiredToken(): void
    {
        $this->expectException(UnauthorizedError::class);
        $this->expectExceptionMessage('Token expired');

        ContentApiToken::verify(
            ['credentials' => [...self::READ_ONLY, 'expiresAt' => (int) (microtime(true) * 1000) - 1000]],
            ['scope' => ['api::model.model.find']],
        );
    }

    public function testThrowsIfTryingToAccessAFullAccessActionWithAReadOnlyKey(): void
    {
        $this->expectException(ForbiddenError::class);

        ContentApiToken::verify(['credentials' => self::READ_ONLY], ['scope' => ['api::model.model.create']]);
    }

    public function testThrowsIfTryingToAccessAnActionWithACustomKeyWithoutThePermission(): void
    {
        $this->expectException(ForbiddenError::class);

        ContentApiToken::verify(
            ['credentials' => [...self::READ_ONLY, 'type' => 'custom'], 'ability' => self::customAbility()],
            ['scope' => ['api::model.model.create']],
        );
    }

    public function testThrowsIfTheCredentialsAreNotPassedInTheAuthObject(): void
    {
        $this->expectException(UnauthorizedError::class);

        ContentApiToken::verify([], ['scope' => ['api::model.model.create']]);
    }

    public function testAFullAccessTokenIsNeededWhenNoScopeIsPassed(): void
    {
        self::assertNull(ContentApiToken::verify(['credentials' => [...self::READ_ONLY, 'type' => 'full-access']], []));
    }

    public function testThrowsIfNoScopeIsPassedWithAReadOnlyToken(): void
    {
        $this->expectException(ForbiddenError::class);

        ContentApiToken::verify(['credentials' => self::READ_ONLY], []);
    }

    public function testThrowsIfNoScopeIsPassedWithACustomToken(): void
    {
        $this->expectException(ForbiddenError::class);

        ContentApiToken::verify(['credentials' => [...self::READ_ONLY, 'type' => 'custom']], []);
    }
}
