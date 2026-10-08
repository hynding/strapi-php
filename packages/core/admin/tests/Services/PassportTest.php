<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Services;

require_once __DIR__ . '/../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Services\Passport;
use Strapi\Admin\Services\Passport\LocalStrategy;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Strapi;

/** Port of server/src/services/__tests__/passport.test.ts. */
final class PassportTest extends TestCase
{
    private static ?Strapi $strapi = null;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedAdminApp::boot();
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

    public function testRegistersTheLocalProviderAndInitsIt(): void
    {
        $passport = new Passport(self::strapi());
        $strategies = $passport->getPassportStrategies();

        self::assertCount(1, $strategies);
        self::assertInstanceOf(LocalStrategy::class, $strategies[0]);
        self::assertSame('local', $strategies[0]->name);

        $middleware = self::strapi()->service('admin::passport')->init();
        self::assertSame('next', $middleware(BootedAdminApp::ctx(), static fn (): string => 'next'), 'passport.initialize() calls next');
    }

    public function testRegistersTheConfiguredAuthEvents(): void
    {
        $received = [];
        self::strapi()->config()->set('admin.auth.events', [
            'onConnectionSuccess' => static function (mixed $payload) use (&$received): void {
                $received[] = $payload;
            },
            'unknown' => static fn (): null => null,
        ]);

        (new Passport(self::strapi()))->registerAuthEvents();
        self::strapi()->eventHub()->emit('admin.auth.success', ['provider' => 'local']);

        self::assertSame([['provider' => 'local']], $received);
    }

    public function testTheLocalStrategyCallsBackWithTheErrorWhenTheCredentialsCheckFails(): void
    {
        $ctx = BootedAdminApp::ctx('POST', '/admin/login', ['email' => 'Nobody@Strapi.io', 'password' => 'secret']);

        self::assertSame([null, false, ['message' => 'Invalid credentials']], LocalStrategy::createLocalStrategy(self::strapi())->authenticate($ctx));
    }

    public function testTheLocalStrategyFailsWithMissingCredentials(): void
    {
        $ctx = BootedAdminApp::ctx('POST', '/admin/login', ['email' => 'a@strapi.io']);

        self::assertSame([null, false, ['message' => 'Missing credentials']], LocalStrategy::createLocalStrategy(self::strapi())->authenticate($ctx));
    }

    public function testTheLocalStrategyCallsBackWithTheProfileWhenTheCredentialsCheckSucceeds(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'passport@strapi.io', 'password' => 'Password123']);
        $ctx = BootedAdminApp::ctx('POST', '/admin/login', ['email' => 'PASSPORT@strapi.io', 'password' => 'Password123']);

        [$error, $profile] = LocalStrategy::createLocalStrategy(self::strapi())->authenticate($ctx);

        self::assertNull($error);
        self::assertSame($user['id'], $profile['id'] ?? null, 'the email is lowercased before the lookup');
    }
}
