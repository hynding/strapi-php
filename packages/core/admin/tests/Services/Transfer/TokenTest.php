<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Services\Transfer;

require_once __DIR__ . '/../../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Services\Transfer\Token;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\NotFoundError;
use Strapi\Utils\Errors\ValidationError;

/** Port of server/src/services/__tests__/transfer/token.test.ts, against a booted app. */
final class TokenTest extends TestCase
{
    private static ?Strapi $strapi = null;

    /** @var list<array{0: string, 1: mixed}> */
    private static array $events = [];

    private static int $counter = 0;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedAdminApp::boot();
        self::$strapi->config()->set('admin.transfer.token.salt', 'transfer-salt');
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

    private static function token(): Token
    {
        return (self::$strapi ?? throw new \LogicException('not booted'))->service('admin::transfer')->token;
    }

    private static function tokenName(): string
    {
        return 'transfer_' . ++self::$counter;
    }

    public function testCreatesAToken(): void
    {
        $name = self::tokenName();
        $res = self::token()->create(['name' => $name, 'description' => 'd', 'permissions' => ['push']]);

        self::assertSame(256, strlen($res['accessKey']));
        self::assertSame(['push'], $res['permissions']);
        self::assertNull($res['lifespan']);
        self::assertNull($res['expiresAt']);
        $row = self::$strapi?->db()->query('admin::transfer-token')->findOne(['where' => ['id' => $res['id']]]);
        self::assertSame(hash_hmac('sha512', $res['accessKey'], 'transfer-salt'), $row['accessKey'] ?? null);
        self::assertSame(['token.create', [
            'tokenId' => $res['id'], 'name' => $name, 'kind' => 'transfer', 'description' => 'd',
            'lifespan' => null, 'expiresAt' => null, 'permissions' => ['push'],
        ]], self::$events[0]);
    }

    public function testCreatesANewTokenWithLifespan(): void
    {
        $lifespan = 30 * 24 * 3600 * 1000;
        $res = self::token()->create(['name' => self::tokenName(), 'permissions' => ['pull'], 'lifespan' => $lifespan]);

        self::assertSame((string) $lifespan, $res['lifespan']);
        self::assertNotNull($res['expiresAt']);
    }

    public function testItThrowsWhenCreatingATokenWithInvalidLifespan(): void
    {
        $this->expectException(ValidationError::class);

        self::token()->create(['name' => self::tokenName(), 'permissions' => ['push'], 'lifespan' => 1234]);
    }

    public function testCreatesATokenWithDuplicatePermissionsIgnoringDuplicates(): void
    {
        $res = self::token()->create(['name' => self::tokenName(), 'permissions' => ['push', 'pull', 'push']]);

        self::assertEqualsCanonicalizing(['push', 'pull'], $res['permissions']);
    }

    public function testCreatesATokenWithInvalidPermissionsShouldThrow(): void
    {
        $this->expectExceptionObject(new ValidationError('Unknown permissions provided: invalid-action'));

        self::token()->create(['name' => self::tokenName(), 'permissions' => ['push', 'invalid-action']]);
    }

    public function testATooShortAccessKeyIsRejected(): void
    {
        $this->expectException(\AssertionError::class);
        $this->expectExceptionMessage('Access key needs to have at least 15 characters');

        self::token()->create(['name' => self::tokenName(), 'permissions' => ['push'], 'accessKey' => 'short']);
    }

    public function testItListsGetsRevokesAndRegeneratesTokens(): void
    {
        $token = self::token()->create(['name' => 'z-' . self::tokenName(), 'permissions' => ['push']]);

        self::assertContains($token['id'], array_column(self::token()->list(), 'id'));
        self::assertSame(['push'], self::token()->getById($token['id'])['permissions'] ?? null);
        self::assertSame($token['id'], self::token()->getByName($token['name'])['id'] ?? null);
        self::assertNull(self::token()->getById(424242));
        self::assertNull(self::token()->getBy());

        $regenerated = self::token()->regenerate($token['id']);
        self::assertSame(['id', 'name', 'accessKey'], array_keys($regenerated));
        self::assertNotSame($token['accessKey'], $regenerated['accessKey']);
        self::assertSame($token['id'], self::token()->getBy(['accessKey' => self::token()->hash($regenerated['accessKey'])])['id'] ?? null);

        $deleted = self::token()->revoke($token['id']);
        self::assertSame($token['id'], $deleted['id'] ?? null);
        self::assertNull(self::token()->revoke($token['id']));
        self::assertSame(['token.create', 'token.regenerate', 'token.delete'], array_column(self::$events, 0));
    }

    public function testRegenerateThrowsANotFoundIfTheIdIsNotFound(): void
    {
        $this->expectExceptionObject(new NotFoundError('The provided token id does not exist'));

        self::token()->regenerate(424242);
    }

    public function testUpdatesATokenAndOnlyEmitsWhenSomethingChanged(): void
    {
        $token = self::token()->create(['name' => self::tokenName(), 'description' => 'd', 'permissions' => ['push']]);
        self::$events = [];

        $updated = self::token()->update($token['id'], ['description' => 'e', 'permissions' => ['pull']]);
        self::assertSame('e', $updated['description']);
        self::assertSame(['pull'], $updated['permissions']);
        self::assertSame([
            'description' => ['before' => 'd', 'after' => 'e'],
            'permissions' => ['before' => ['push'], 'after' => ['pull']],
        ], self::$events[0][1]['changes']);

        self::$events = [];
        self::token()->update($token['id'], ['description' => 'e']);
        self::assertSame([], self::$events);
    }

    public function testUpdatesPermissionsFieldOfATokenWithUnknownPermissions(): void
    {
        $token = self::token()->create(['name' => self::tokenName(), 'permissions' => ['push']]);

        $this->expectExceptionObject(new ValidationError('Unknown permissions provided: unknown'));
        self::token()->update($token['id'], ['permissions' => ['unknown']]);
    }

    public function testHashThrowsWithoutASalt(): void
    {
        self::$strapi?->config()->set('admin.transfer.token.salt', '');
        try {
            $this->expectException(\TypeError::class);
            self::token()->hash('x');
        } finally {
            self::$strapi?->config()->set('admin.transfer.token.salt', 'transfer-salt');
        }
    }
}
