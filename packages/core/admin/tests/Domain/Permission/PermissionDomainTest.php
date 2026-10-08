<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Domain\Permission;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Admin\Domain\Permission\Permission as Domain;

/** Port of server/src/domain/permission/__tests__/permission-domain.test.ts (currying cases use the uncurried form). */
final class PermissionDomainTest extends TestCase
{
    public function testAddConditionInitsTheArray(): void
    {
        $permission = [];

        $newPermission = Domain::addCondition('foo', $permission);

        self::assertArrayNotHasKey('conditions', $permission);
        self::assertSame(['foo'], $newPermission['conditions']);
    }

    public function testAddConditionAppends(): void
    {
        $permission = ['conditions' => ['foo']];

        $newPermission = Domain::addCondition('bar', $permission);

        self::assertSame(['foo'], $permission['conditions']);
        self::assertSame(['foo', 'bar'], $newPermission['conditions']);
    }

    public function testAddConditionDoesNotDuplicate(): void
    {
        self::assertSame(['foo'], Domain::addCondition('foo', ['conditions' => ['foo']])['conditions']);
    }

    public function testRemoveCondition(): void
    {
        $permission = ['conditions' => ['foo', 'bar']];

        $newPermission = Domain::removeCondition('foo', $permission);

        self::assertSame(['foo', 'bar'], $permission['conditions']);
        self::assertSame(['bar'], $newPermission['conditions']);
    }

    public function testRemoveMissingConditionDoesNothing(): void
    {
        self::assertSame(['foo', 'bar'], Domain::removeCondition('foobar', ['conditions' => ['foo', 'bar']])['conditions']);
    }

    public function testCreateRemovesUnwantedFields(): void
    {
        $newPermission = Domain::create(['id' => 1, 'action' => 'foo', 'subject' => 'bar', 'properties' => [], 'conditions' => [], 'foo' => 'bar']);

        self::assertArrayNotHasKey('foo', $newPermission);
        self::assertSame([], $newPermission['actionParameters']);
    }

    public function testSanitizePermissionFields(): void
    {
        self::assertArrayNotHasKey('foo', Domain::sanitizePermissionFields(['action' => 'foo', 'subject' => 'bar', 'properties' => [], 'foo' => 'bar']));
    }

    public function testSetNewProperty(): void
    {
        $permission = ['properties' => []];

        $newPermission = Domain::setProperty('foo', 'bar', $permission);

        self::assertSame([], $permission['properties']);
        self::assertSame(['foo' => 'bar'], $newPermission['properties']);
    }

    public function testUpdateExistingProperty(): void
    {
        $permission = ['properties' => ['foo' => 'bar']];

        $newPermission = Domain::setProperty('foo', 'foobar', $permission);

        self::assertSame(['foo' => 'bar'], $permission['properties']);
        self::assertSame(['foo' => 'foobar'], $newPermission['properties']);
    }

    public function testDeepSetProperty(): void
    {
        $permission = ['properties' => ['foo' => ['bar' => ['foobar' => null]]], 'bar' => 'foo'];

        $newPermission = Domain::setProperty('foo.bar.foobar', 1, $permission);

        self::assertNull($permission['properties']['foo']['bar']['foobar']);
        self::assertSame(1, $newPermission['properties']['foo']['bar']['foobar']);
    }

    public function testDeleteExistingProperty(): void
    {
        $permission = ['properties' => ['foo' => 'bar', 'bar' => 'foo']];

        $newPermission = Domain::deleteProperty('foo', $permission);

        self::assertSame(['foo' => 'bar', 'bar' => 'foo'], $permission['properties']);
        self::assertSame(['bar' => 'foo'], $newPermission['properties']);
    }

    public function testDeleteMissingPropertyDoesNothing(): void
    {
        self::assertSame(['foo' => 'bar'], Domain::deleteProperty('bar', ['properties' => ['foo' => 'bar']])['properties']);
    }

    public function testDeepDeleteProperty(): void
    {
        $permission = ['properties' => ['foo' => ['bar' => ['foobar' => null, 'barfoo' => 2]]], 'bar' => 'foo'];

        $newPermission = Domain::deleteProperty('foo.bar.barfoo', $permission);

        self::assertSame(['foobar' => null, 'barfoo' => 2], $permission['properties']['foo']['bar']);
        self::assertSame(['foobar' => null], $newPermission['properties']['foo']['bar']);
    }

    public function testToPermissionSingle(): void
    {
        $newPermission = Domain::toPermission(['id' => 1, 'action' => 'foo', 'actionParameters' => [], 'subject' => 'bar', 'properties' => [], 'conditions' => [], 'foo' => 'bar']);

        self::assertArrayNotHasKey('foo', $newPermission);
    }

    public function testToPermissionMany(): void
    {
        $newPermissions = Domain::toPermission([
            ['id' => 1, 'action' => 'foo', 'actionParameters' => [], 'subject' => 'bar', 'properties' => [], 'conditions' => [], 'foo' => 'bar'],
            ['id' => 2, 'action' => 'foo', 'subject' => 'bar', 'properties' => [], 'conditions' => [], 'foo' => 'bar'],
        ]);

        self::assertCount(2, $newPermissions);
        foreach ($newPermissions as $p) {
            self::assertIsArray($p);
            self::assertArrayNotHasKey('foo', $p);
        }
    }

    public function testGetProperty(): void
    {
        self::assertSame('bar', Domain::getProperty('foo', ['properties' => ['foo' => 'bar']]));
    }

    public function testGetDeepProperty(): void
    {
        self::assertSame('foobar', Domain::getProperty('foo.bar', ['properties' => ['foo' => ['bar' => 'foobar']]]));
    }

    public function testGetMissingProperty(): void
    {
        self::assertNull(Domain::getProperty('bar', ['properties' => ['foo' => 'bar']]));
    }

    public function testGetPropertyWithoutProperties(): void
    {
        self::assertNull(Domain::getProperty('foo', []));
    }

    private static function conditionProvider(): object
    {
        return new class () {
            public function has(string $condition): bool
            {
                return in_array($condition, ['foo', 'bar'], true);
            }
        };
    }

    public function testSanitizeConditionsKeepsValidOnes(): void
    {
        self::assertSame(['foo', 'bar'], Domain::sanitizeConditions(self::conditionProvider(), ['conditions' => ['foo', 'bar']])['conditions']);
    }

    public function testSanitizeConditionsRemovesUnknownOnes(): void
    {
        self::assertSame(['foo'], Domain::sanitizeConditions(self::conditionProvider(), ['conditions' => ['foo', 'foobar']])['conditions']);
    }

    public function testSanitizeConditionsWithoutConditions(): void
    {
        self::assertArrayNotHasKey('conditions', Domain::sanitizeConditions(self::conditionProvider(), []));
    }

    /** @return list<array{array<string, mixed>, list<string>|null}> */
    public static function sanitizeCases(): array
    {
        return [
            [['conditions' => []], []],
            [['conditions' => ['foo']], ['foo']],
            [['conditions' => ['foo', 'foobar']], ['foo']],
            [['conditions' => ['foobar']], []],
            [[], null],
        ];
    }

    /**
     * @param array<string, mixed> $permission
     * @param list<string>|null $expected
     */
    #[DataProvider('sanitizeCases')]
    public function testSanitizeConditionsMatrix(array $permission, ?array $expected): void
    {
        $result = Domain::sanitizeConditions(self::conditionProvider(), $permission);

        if ($expected === null) {
            self::assertArrayNotHasKey('conditions', $result);
        } else {
            self::assertSame($expected, $result['conditions']);
        }
    }
}
