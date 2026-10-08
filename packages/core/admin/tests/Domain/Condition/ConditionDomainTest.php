<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Domain\Condition;

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Domain\Condition\Condition as Domain;

/** Port of server/src/domain/condition/__tests__/condition-domain.test.ts. */
final class ConditionDomainTest extends TestCase
{
    public function testAssignConditionIdDoesNotMutateOriginal(): void
    {
        $condition = ['name' => 'foobar'];

        $newCondition = Domain::assignConditionId($condition);

        self::assertArrayNotHasKey('id', $condition);
        self::assertSame('foobar', $newCondition['name']);
        self::assertSame('api::foobar', $newCondition['id']);
    }

    public function testComputeConditionIdWithoutPlugin(): void
    {
        self::assertSame('api::foobar', Domain::computeConditionId(['name' => 'foobar']));
    }

    public function testComputeConditionIdForAdmin(): void
    {
        self::assertSame('admin::foobar', Domain::computeConditionId(['name' => 'foobar', 'plugin' => 'admin']));
    }

    public function testComputeConditionIdForPlugin(): void
    {
        self::assertSame('plugin::myPlugin.foobar', Domain::computeConditionId(['name' => 'foobar', 'plugin' => 'myPlugin']));
    }

    public function testCreateWithMinimumInformation(): void
    {
        $handler = static fn (): array => ['foo' => 'bar'];

        $result = Domain::create(['handler' => $handler, 'name' => 'foo', 'displayName' => 'Foo']);

        self::assertSame($handler, $result['handler']);
        self::assertSame('api::foo', $result['id']);
        self::assertSame('Foo', $result['displayName']);
        self::assertSame('default', $result['category']);
    }

    public function testCreateHandlesMultipleSteps(): void
    {
        $handler = static fn (): array => ['foo' => 'bar'];

        $result = Domain::create([
            'name' => 'foo',
            'plugin' => 'bar',
            'displayName' => 'Foo',
            'handler' => $handler,
            'invalidAttribute' => 'foobar',
        ]);

        self::assertSame('plugin::bar.foo', $result['id']);
        self::assertSame('default', $result['category']);
        self::assertSame('bar', $result['plugin']);
        self::assertSame('Foo', $result['displayName']);
        self::assertSame($handler, $result['handler']);
        self::assertArrayNotHasKey('invalidAttribute', $result);
    }

    public function testSanitizeKeepsConditionFields(): void
    {
        $condition = array_fill_keys(Domain::conditionFields(), 'foo');

        $sanitized = Domain::sanitizeConditionAttributes($condition);

        self::assertEqualsCanonicalizing(array_keys($condition), array_keys($sanitized));
    }

    public function testSanitizeRemovesOtherAttributes(): void
    {
        $condition = array_fill_keys([...Domain::conditionFields(), 'foo', 'bar'], 'foo');

        $sanitized = Domain::sanitizeConditionAttributes($condition);

        self::assertArrayNotHasKey('foo', $sanitized);
        self::assertArrayNotHasKey('bar', $sanitized);
    }
}
