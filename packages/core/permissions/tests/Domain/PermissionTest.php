<?php

declare(strict_types=1);

namespace Strapi\Permissions\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Strapi\Permissions\Domain\Permission\Permission;

/** Port of domain/permission/__tests__/permission.vitest.test.ts. */
final class PermissionTest extends TestCase
{
    public function testDoesNotShareDefaultsBetweenCreatedPermissions(): void
    {
        $first = Permission::create(['action' => 'first']);
        $first['conditions'][] = 'only-first';
        $first['properties']['modified'] = true;

        self::assertEquals(['action' => 'second', 'conditions' => [], 'properties' => [], 'subject' => null], Permission::create(['action' => 'second']));
    }

    public function testSupportsPartialApplicationWithoutChangingTheOriginalPermission(): void
    {
        $properties = ['fields' => ['title']];
        $permission = ['action' => 'test', 'conditions' => ['first'], 'properties' => $properties];
        $addSecond = Permission::addCondition('second');

        $updated = $addSecond($permission);

        self::assertSame(['first', 'second'], $updated['conditions']);
        self::assertSame(['first'], $permission['conditions']);
        self::assertSame($properties, $updated['properties']);
        self::assertSame($properties['fields'], Permission::getProperty('fields')($updated));
    }

    public function testCreatePicksPermissionFieldsAndAppliesDefaults(): void
    {
        $permission = Permission::create(['action' => 'plugin::users-permissions.user.find', 'subject' => 'plugin::users-permissions.user', 'extra' => 'ignored']);

        self::assertEquals(['action' => 'plugin::users-permissions.user.find', 'subject' => 'plugin::users-permissions.user', 'conditions' => [], 'properties' => []], $permission);
        self::assertArrayNotHasKey('extra', $permission);
    }

    public function testSanitizePermissionFieldsKeepsOnlyKnownFields(): void
    {
        $sanitized = Permission::sanitizePermissionFields(['action' => 'test', 'subject' => 'test', 'properties' => ['fields' => ['title']], 'conditions' => ['admin::is-creator'], 'unknown' => true]);

        self::assertSame(['action' => 'test', 'subject' => 'test', 'properties' => ['fields' => ['title']], 'conditions' => ['admin::is-creator']], $sanitized);
    }

    public function testAddConditionAppendsUniqueConditions(): void
    {
        $base = Permission::create(['action' => 'test', 'conditions' => ['a']]);
        $updated = Permission::addCondition('b', Permission::addCondition('a', $base));

        self::assertSame(['a', 'b'], $updated['conditions']);
    }

    public function testAddConditionInitializesConditionsWhenMissing(): void
    {
        $updated = Permission::addCondition('admin::is-creator', ['action' => 'test']);
        self::assertSame(['admin::is-creator'], $updated['conditions']);
    }

    public function testGetPropertyReadsNestedProperties(): void
    {
        $permission = Permission::create(['action' => 'test', 'properties' => ['fields' => ['title', 'slug']]]);
        self::assertSame(['title', 'slug'], Permission::getProperty('fields', $permission));
        self::assertNull(Permission::getProperty('locales', $permission));
    }
}
