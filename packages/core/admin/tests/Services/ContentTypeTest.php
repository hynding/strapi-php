<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Services;

require_once __DIR__ . '/../StubStrapi.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Admin\Domain\Permission\Permission as PermissionDomain;
use Strapi\Admin\Services\ContentType;
use Strapi\Admin\Tests\StubStrapi;
use Strapi\Core\Strapi;

/** Port of server/src/services/__tests__/content-type.test.ts. */
final class ContentTypeTest extends TestCase
{
    private const array CONTENT_TYPES = [
        'user' => [
            'attributes' => [
                'firstname' => ['type' => 'text', 'required' => true],
                'restaurant' => ['type' => 'component', 'component' => 'restaurant'],
                'car' => ['type' => 'component', 'component' => 'car', 'required' => true],
            ],
        ],
        'country' => [
            'attributes' => [
                'name' => ['type' => 'text'],
                'code' => ['type' => 'text'],
            ],
        ],
    ];

    private const array COMPONENTS = [
        'restaurant' => [
            'attributes' => [
                'name' => ['type' => 'text'],
                'description' => ['type' => 'text'],
                'address' => ['type' => 'component', 'component' => 'address'],
            ],
        ],
        'car' => [
            'attributes' => [
                'model' => ['type' => 'text'],
            ],
        ],
        'address' => [
            'attributes' => [
                'city' => ['type' => 'text'],
                'country' => ['type' => 'text', 'required' => true],
                'gpsCoordinates' => ['type' => 'component', 'component' => 'gpsCoordinates'],
            ],
        ],
        'gpsCoordinates' => [
            'attributes' => [
                'lat' => ['type' => 'text'],
                'long' => ['type' => 'text'],
            ],
        ],
    ];

    private static ?Strapi $strapi = null;

    public static function setUpBeforeClass(): void
    {
        $strapi = StubStrapi::create();
        StubStrapi::addContentTypes($strapi, self::CONTENT_TYPES);
        StubStrapi::addComponents($strapi, self::COMPONENTS);

        $actionProvider = new class () {
            /** @return array<string, mixed> */
            public function get(string $id): array
            {
                return ['options' => ['applyToProperties' => ['fields']]];
            }
        };
        StubStrapi::setService($strapi, 'admin::permission', new class ($actionProvider) {
            public function __construct(public readonly object $actionProvider)
            {
            }
        });

        self::$strapi = $strapi;
    }

    private static function service(): ContentType
    {
        return new ContentType(self::$strapi ?? throw new \LogicException());
    }

    /** @return array<string, mixed> */
    private static function components(): array
    {
        return (self::$strapi ?? throw new \LogicException())->components();
    }

    private static function userModel(): mixed
    {
        return (self::$strapi ?? throw new \LogicException())->contentTypes()['user'];
    }

    /** @return list<array{int, list<string>}> */
    public static function nestingCases(): array
    {
        return [
            [1, ['firstname', 'restaurant', 'car']],
            [2, ['firstname', 'restaurant.name', 'restaurant.description', 'restaurant.address', 'car.model']],
            [3, ['firstname', 'restaurant.name', 'restaurant.description', 'restaurant.address.city', 'restaurant.address.country', 'restaurant.address.gpsCoordinates', 'car.model']],
            [4, ['firstname', 'restaurant.name', 'restaurant.description', 'restaurant.address.city', 'restaurant.address.country', 'restaurant.address.gpsCoordinates.lat', 'restaurant.address.gpsCoordinates.long', 'car.model']],
            [5, ['firstname', 'restaurant.name', 'restaurant.description', 'restaurant.address.city', 'restaurant.address.country', 'restaurant.address.gpsCoordinates.lat', 'restaurant.address.gpsCoordinates.long', 'car.model']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('nestingCases')]
    public function testGetNestedFields(int $nestingLevel, array $expected): void
    {
        self::assertSame($expected, self::service()->getNestedFields(self::userModel(), [
            'nestingLevel' => $nestingLevel,
            'components' => self::components(),
        ]));
    }

    /** @return list<array{list<string>|null, list<string>}> */
    public static function requiredOnlyCases(): array
    {
        return [
            [null, ['firstname', 'car']],
            [['firstname', 'car'], ['firstname', 'car']],
            [['restaurant.description'], ['firstname', 'car']],
            [['restaurant.address'], ['firstname', 'restaurant.address.country', 'car']],
            [['restaurant.address.city'], ['firstname', 'restaurant.address.country', 'car']],
            [['firstname', 'restaurant.address.country', 'car'], ['firstname', 'restaurant.address.country', 'car']],
        ];
    }

    /**
     * @param list<string>|null $existingFields
     * @param list<string> $expected
     */
    #[DataProvider('requiredOnlyCases')]
    public function testGetNestedFieldsRequiredOnly(?array $existingFields, array $expected): void
    {
        $options = ['components' => self::components(), 'requiredOnly' => true];
        if ($existingFields !== null) {
            $options['existingFields'] = $existingFields;
        }

        self::assertSame($expected, self::service()->getNestedFields(self::userModel(), $options));
    }

    /** @return list<array{int, list<string>}> */
    public static function intermediateCases(): array
    {
        return [
            [1, ['firstname', 'restaurant', 'car']],
            [2, ['firstname', 'restaurant', 'restaurant.name', 'restaurant.description', 'restaurant.address', 'car', 'car.model']],
            [3, ['firstname', 'restaurant', 'restaurant.name', 'restaurant.description', 'restaurant.address', 'restaurant.address.city', 'restaurant.address.country', 'restaurant.address.gpsCoordinates', 'car', 'car.model']],
            [4, ['firstname', 'restaurant', 'restaurant.name', 'restaurant.description', 'restaurant.address', 'restaurant.address.city', 'restaurant.address.country', 'restaurant.address.gpsCoordinates', 'restaurant.address.gpsCoordinates.lat', 'restaurant.address.gpsCoordinates.long', 'car', 'car.model']],
            [5, ['firstname', 'restaurant', 'restaurant.name', 'restaurant.description', 'restaurant.address', 'restaurant.address.city', 'restaurant.address.country', 'restaurant.address.gpsCoordinates', 'restaurant.address.gpsCoordinates.lat', 'restaurant.address.gpsCoordinates.long', 'car', 'car.model']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('intermediateCases')]
    public function testGetNestedFieldsWithIntermediate(int $nestingLevel, array $expected): void
    {
        self::assertSame($expected, self::service()->getNestedFieldsWithIntermediate(self::userModel(), [
            'nestingLevel' => $nestingLevel,
            'components' => self::components(),
        ]));
    }

    /**
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private static function expectedPermission(string $subject, array $fields, string $action = 'action-1'): array
    {
        return [
            'actionParameters' => [],
            'conditions' => [],
            'properties' => ['fields' => $fields],
            'subject' => $subject,
            'action' => $action,
        ];
    }

    public function testPermissionsWithNestedFieldsNoNesting(): void
    {
        $result = self::service()->getPermissionsWithNestedFields([
            ['actionId' => 'action-1', 'subjects' => ['country'], 'options' => ['applyToProperties' => ['fields']]],
        ]);

        self::assertEquals([self::expectedPermission('country', ['name', 'code'])], $result);
    }

    public function testPermissionsWithNestedFieldsLevel1(): void
    {
        $result = self::service()->getPermissionsWithNestedFields([
            ['actionId' => 'action-1', 'subjects' => ['country', 'user'], 'options' => ['applyToProperties' => ['fields']]],
        ], ['nestingLevel' => 1]);

        self::assertEquals([
            self::expectedPermission('country', ['name', 'code']),
            self::expectedPermission('user', ['firstname', 'restaurant', 'car']),
        ], $result);
    }

    public function testPermissionsWithNestedFieldsLevel2(): void
    {
        $result = self::service()->getPermissionsWithNestedFields([
            ['actionId' => 'action-1', 'subjects' => ['country', 'user'], 'options' => ['applyToProperties' => ['fields']]],
        ], ['nestingLevel' => 2]);

        self::assertEquals([
            self::expectedPermission('country', ['name', 'code']),
            self::expectedPermission('user', ['firstname', 'restaurant.name', 'restaurant.description', 'restaurant.address', 'car.model']),
        ], $result);
    }

    public function testPermissionsWithNestedFieldsDefaultLevel(): void
    {
        $result = self::service()->getPermissionsWithNestedFields([
            ['actionId' => 'action-1', 'subjects' => ['country', 'user'], 'options' => ['applyToProperties' => ['fields']]],
        ]);

        self::assertEquals([
            self::expectedPermission('country', ['name', 'code']),
            self::expectedPermission('user', ['firstname', 'restaurant.name', 'restaurant.description', 'restaurant.address.city', 'restaurant.address.country', 'restaurant.address.gpsCoordinates.lat', 'restaurant.address.gpsCoordinates.long', 'car.model']),
        ], $result);
    }

    /** @return list<array{mixed, list<string>}> */
    public static function cleanCases(): array
    {
        return [
            [null, []],
            [['firstname', 'car'], ['firstname', 'car.model']],
            [['restaurant.description'], ['restaurant.description']],
            [['restaurant.address'], ['restaurant.address.city', 'restaurant.address.country', 'restaurant.address.gpsCoordinates.lat', 'restaurant.address.gpsCoordinates.long']],
            [['restaurant.address.city'], ['restaurant.address.city']],
            [['firstname', 'restaurant.address.country', 'car'], ['firstname', 'restaurant.address.country', 'car.model']],
            [['restaurant'], ['restaurant.name', 'restaurant.description', 'restaurant.address.city', 'restaurant.address.country', 'restaurant.address.gpsCoordinates.lat', 'restaurant.address.gpsCoordinates.long']],
            [['restaurant.name', 'restaurant.address', 'restaurant.address.country'], ['restaurant.name', 'restaurant.address.city', 'restaurant.address.country', 'restaurant.address.gpsCoordinates.lat', 'restaurant.address.gpsCoordinates.long']],
            [['nonexistent.field', 'firstname'], ['firstname']],
            [['restaurant.address.gpsCoordinates.lat'], ['restaurant.address.gpsCoordinates.lat']],
        ];
    }

    /** @param list<string> $expectedFields */
    #[DataProvider('cleanCases')]
    public function testCleanPermissionFields(mixed $fields, array $expectedFields): void
    {
        $permissions = [PermissionDomain::create(['action' => 'foo', 'subject' => 'user', 'properties' => ['fields' => $fields]])];

        self::assertEquals([self::expectedPermission('user', $expectedFields, 'foo')], self::service()->cleanPermissionFields($permissions));
    }

    public function testCacheByCleanPermissionFieldsSameSubject(): void
    {
        $permissions = array_map(PermissionDomain::create(...), [
            ['action' => 'foo', 'subject' => 'user', 'properties' => ['fields' => ['firstname', 'restaurant.address']]],
            ['action' => 'foo', 'subject' => 'user', 'properties' => ['fields' => ['car']]],
            ['action' => 'foo', 'subject' => 'user', 'properties' => ['fields' => ['firstname', 'restaurant.description']]],
        ]);

        $res = self::service()->cleanPermissionFields($permissions);

        self::assertCount(3, $res);
        self::assertEquals(self::expectedPermission('user', ['firstname', 'restaurant.address.city', 'restaurant.address.country', 'restaurant.address.gpsCoordinates.lat', 'restaurant.address.gpsCoordinates.long'], 'foo'), $res[0]);
        self::assertEquals(self::expectedPermission('user', ['car.model'], 'foo'), $res[1]);
        self::assertEquals(self::expectedPermission('user', ['firstname', 'restaurant.description'], 'foo'), $res[2]);
    }

    public function testCacheSeparateEntriesForDifferentSubjects(): void
    {
        $permissions = array_map(PermissionDomain::create(...), [
            ['action' => 'foo', 'subject' => 'user', 'properties' => ['fields' => ['firstname']]],
            ['action' => 'foo', 'subject' => 'country', 'properties' => ['fields' => ['name']]],
            ['action' => 'foo', 'subject' => 'user', 'properties' => ['fields' => ['car']]],
        ]);

        $res = self::service()->cleanPermissionFields($permissions);

        self::assertCount(3, $res);
        self::assertSame('user', $res[0]['subject']);
        self::assertContains('firstname', $res[0]['properties']['fields']);
        self::assertSame('user', $res[2]['subject']);
        self::assertSame(['car.model'], $res[2]['properties']['fields']);
        self::assertSame('country', $res[1]['subject']);
        self::assertContains('name', $res[1]['properties']['fields']);
    }

    public function testMixedPermissions(): void
    {
        $permissions = array_map(PermissionDomain::create(...), [
            ['action' => 'read', 'subject' => 'user', 'properties' => ['fields' => ['firstname', 'restaurant']]],
            ['action' => 'create', 'subject' => 'user', 'properties' => ['fields' => ['firstname']]],
            ['action' => 'update', 'subject' => 'user', 'properties' => ['fields' => ['restaurant.address.country', 'car']]],
            ['action' => 'delete', 'subject' => 'user', 'properties' => ['fields' => ['firstname', 'car.model']]],
        ]);

        $res = self::service()->cleanPermissionFields($permissions);

        self::assertCount(4, $res);
        foreach ($res as $perm) {
            self::assertArrayHasKey('action', $perm);
            self::assertArrayHasKey('conditions', $perm);
            self::assertArrayHasKey('fields', $perm['properties']);
            self::assertSame('user', $perm['subject']);
        }
    }
}
