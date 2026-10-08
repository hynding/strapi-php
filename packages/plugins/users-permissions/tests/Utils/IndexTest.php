<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Tests\Utils;

require_once __DIR__ . '/../BootedApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Tests\BootedApp;
use Strapi\Plugin\UsersPermissions\Utils\Utils;
use Strapi\Types\Schema\Schema;

/**
 * Port of server/src/utils/__tests__/index.test.js. Upstream mocks `crypto.randomInt` /
 * `crypto.randomUUID` and the user query; here the random functions are {@see Utils::$randomInt}
 * / {@see Utils::$randomUUID} and the users exist in the booted app's database.
 */
final class IndexTest extends TestCase
{
    private static ?Strapi $strapi = null;

    /** @var list<array{0: int, 1: int}> */
    private array $randomIntCalls = [];

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedApp::boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    protected function setUp(): void
    {
        self::strapi()->db()->query('plugin::users-permissions.user')->deleteMany();
        $this->randomIntCalls = [];
    }

    protected function tearDown(): void
    {
        Utils::$randomInt = null;
        Utils::$randomUUID = null;
    }

    private static function strapi(): Strapi
    {
        return self::$strapi ?? throw new \LogicException('not booted');
    }

    /** @param int ...$values returned in order, the last one repeating */
    private function mockRandomInt(int ...$values): void
    {
        Utils::$randomInt = function (int $min, int $max) use (&$values): int {
            $this->randomIntCalls[] = [$min, $max];

            return count($values) > 1 ? array_shift($values) : $values[0];
        };
    }

    private static function takeUsername(string $username): void
    {
        BootedApp::createUser(self::strapi(), ['username' => $username]);
    }

    public function testIsUsernameTakenReturnsFalseWhenUsernameIsNotFound(): void
    {
        self::assertFalse(Utils::isUsernameTaken(self::strapi(), 'joe'));
    }

    public function testIsUsernameTakenReturnsTrueWhenUsernameIsFound(): void
    {
        self::takeUsername('joe');

        self::assertTrue(Utils::isUsernameTaken(self::strapi(), 'joe'));
    }

    public function testFindValidUsernameReturnsBasenameWhenAvailableAndMeetsMinLength(): void
    {
        self::assertSame('joe', Utils::findValidUsername(self::strapi(), 'joe'));
    }

    public function testFindValidUsernameReturnsSuffixedUsernameWhenBasenameIsTaken(): void
    {
        self::takeUsername('joe');
        $this->mockRandomInt(1234);

        self::assertSame('joe1234', Utils::findValidUsername(self::strapi(), 'joe'));
        self::assertSame([[1000, 9999]], $this->randomIntCalls);
    }

    public function testFindValidUsernameSkipsBasenameWhenShorterThanMinLength(): void
    {
        $this->mockRandomInt(5678);

        // Should not try 'jo' first; goes straight to 'jo5678'
        self::assertSame('jo5678', Utils::findValidUsername(self::strapi(), 'jo'));
    }

    public function testFindValidUsernameRetriesOnSuffixCollision(): void
    {
        self::takeUsername('joe');
        self::takeUsername('joe1111');
        $this->mockRandomInt(1111, 2222);

        self::assertSame('joe2222', Utils::findValidUsername(self::strapi(), 'joe'));
        self::assertCount(2, $this->randomIntCalls);
    }

    public function testFindValidUsernameFallsBackToUuidWhenAll10AttemptsAreTaken(): void
    {
        self::takeUsername('joe');
        self::takeUsername('joe1234');
        $this->mockRandomInt(1234);
        $uuidCalls = 0;
        Utils::$randomUUID = static function () use (&$uuidCalls): string {
            $uuidCalls++;

            return 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        };

        self::assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', Utils::findValidUsername(self::strapi(), 'joe'));
        self::assertSame(1, $uuidCalls);
        // 1 basename attempt + 10 suffix attempts = 11 total
        self::assertCount(10, $this->randomIntCalls);
    }

    public function testFindValidUsernameRespectsCustomMinLengthFromModelAttributes(): void
    {
        $model = self::strapi()->getModel('plugin::users-permissions.user');
        self::assertNotNull($model);
        $attributes = $model->attributes;
        $attributes['username'] = [...$attributes['username'], 'minLength' => 6];
        self::replaceModel(new Schema(
            $model->uid, $model->modelType, $model->kind, $model->modelName, $model->globalId, $model->collectionName,
            $model->plugin, $model->apiName, $model->category, $model->info, $model->options, $model->pluginOptions, $attributes, $model->config,
        ));
        $this->mockRandomInt(9999);

        try {
            // 'joe' length 3 < minLength 6 → skip basename, use suffix
            self::assertSame('joe9999', Utils::findValidUsername(self::strapi(), 'joe'));
        } finally {
            self::replaceModel($model);
        }
    }

    /** Upstream's test returns a different model from its `strapi.getModel` mock; here the memoized model is swapped. */
    private static function replaceModel(Schema $model): void
    {
        $cache = new \ReflectionProperty(Strapi::class, 'modelCache');
        $cache->setValue(self::strapi(), [...$cache->getValue(self::strapi()), $model->uid => $model]);
    }
}
