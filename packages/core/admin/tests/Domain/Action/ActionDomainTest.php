<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Domain\Action;

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Domain\Action\Action as Domain;

/** Port of server/src/domain/action/__tests__/action-domain.test.ts (the currying cases do not apply in PHP). */
final class ActionDomainTest extends TestCase
{
    public function testAppliesToPropertyFalseWhenApplyToPropertiesIsNil(): void
    {
        self::assertFalse(Domain::appliesToProperty('foo', ['options' => []]));
    }

    public function testAppliesToPropertyTrueWhenApplyToPropertiesContainsProperty(): void
    {
        self::assertTrue(Domain::appliesToProperty('foo', ['options' => ['applyToProperties' => ['foo', 'bar']]]));
    }

    public function testAppliesToSubjectFalseWhenSubjectsIsNotAnArray(): void
    {
        self::assertFalse(Domain::appliesToSubject('foo', []));
    }

    public function testAppliesToSubjectTrueWhenSubjectsContainsSubject(): void
    {
        self::assertTrue(Domain::appliesToSubject('foo', ['subjects' => ['foo', 'bar']]));
    }

    public function testAppliesToSubjectFalseWhenSubjectsDoesntContainSubject(): void
    {
        self::assertFalse(Domain::appliesToSubject('foobar', ['subjects' => ['foo', 'bar']]));
    }

    public function testAssignActionIdDoesNotMutateOriginal(): void
    {
        $action = ['uid' => 'foobar'];

        $newAction = Domain::assignActionId($action);

        self::assertArrayNotHasKey('actionId', $action);
        self::assertSame('foobar', $newAction['uid']);
        self::assertSame('api::foobar', $newAction['actionId']);
    }

    public function testKeepsSubCategoryForSettings(): void
    {
        self::assertSame('foo', Domain::assignOrOmitSubCategory(['section' => 'settings', 'subCategory' => 'foo'])['subCategory']);
    }

    public function testKeepsSubCategoryForPlugins(): void
    {
        self::assertSame('foo', Domain::assignOrOmitSubCategory(['section' => 'plugins', 'subCategory' => 'foo'])['subCategory']);
    }

    public function testAddsGenericSubCategory(): void
    {
        self::assertSame('general', Domain::assignOrOmitSubCategory(['section' => 'settings'])['subCategory']);
    }

    public function testDoesNotAddSubCategoryOutsideSettingsAndPlugins(): void
    {
        self::assertArrayNotHasKey('subCategory', Domain::assignOrOmitSubCategory(['section' => 'contentTypes']));
    }

    public function testOmitsSubCategoryOutsideSettingsAndPlugins(): void
    {
        self::assertArrayNotHasKey('subCategory', Domain::assignOrOmitSubCategory(['section' => 'contentTypes', 'subCategory' => 'foo']));
    }

    public function testCreateWithMinimumInformation(): void
    {
        $result = Domain::create(['section' => 'contentTypes', 'uid' => 'foo']);

        self::assertSame('contentTypes', $result['section']);
        self::assertSame('api::foo', $result['actionId']);
        self::assertSame(['applyToProperties' => null], $result['options']);
    }

    public function testCreateHandlesMultipleSteps(): void
    {
        $result = Domain::create([
            'section' => 'settings',
            'uid' => 'foo',
            'pluginName' => 'bar',
            'subCategory' => 'foobar',
            'invalidAttribute' => 'foobar',
        ]);

        self::assertSame('settings', $result['section']);
        self::assertSame('bar', $result['pluginName']);
        self::assertSame('plugin::bar.foo', $result['actionId']);
        self::assertSame('foobar', $result['subCategory']);
        self::assertArrayNotHasKey('invalidAttribute', $result);
        self::assertArrayNotHasKey('uid', $result);
    }

    public function testComputeActionIdWithoutPluginName(): void
    {
        self::assertSame('api::foobar', Domain::computeActionId(['uid' => 'foobar']));
    }

    public function testComputeActionIdForAdmin(): void
    {
        self::assertSame('admin::foobar', Domain::computeActionId(['uid' => 'foobar', 'pluginName' => 'admin']));
    }

    public function testComputeActionIdForPlugin(): void
    {
        self::assertSame('plugin::myPlugin.foobar', Domain::computeActionId(['uid' => 'foobar', 'pluginName' => 'myPlugin']));
    }

    public function testDefaultAttributesAreActionFields(): void
    {
        foreach (array_keys(Domain::getDefaultActionAttributes()) as $attribute) {
            self::assertContains($attribute, Domain::actionFields());
        }
    }

    public function testSanitizeKeepsActionFields(): void
    {
        $action = array_fill_keys(Domain::ACTION_FIELDS, 'foo');

        $sanitized = Domain::sanitizeActionAttributes($action);

        $sortedA = array_keys($sanitized);
        $sortedB = array_keys($action);
        sort($sortedA);
        sort($sortedB);
        self::assertSame($sortedB, $sortedA);
    }

    public function testSanitizeRemovesOtherAttributes(): void
    {
        $action = array_fill_keys([...Domain::ACTION_FIELDS, 'foo', 'bar'], 'foo');

        $sanitized = Domain::sanitizeActionAttributes($action);

        self::assertArrayNotHasKey('foo', $sanitized);
        self::assertArrayNotHasKey('bar', $sanitized);
        self::assertCount(count(Domain::ACTION_FIELDS), $sanitized);
    }
}
