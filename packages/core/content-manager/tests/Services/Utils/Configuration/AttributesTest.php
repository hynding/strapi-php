<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Services\Utils\Configuration;

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Services\Utils\Configuration\Attributes;

/** Port of server/src/services/utils/configuration/__tests__/attributes.test.ts. */
final class AttributesTest extends TestCase
{
    /**
     * @param array<string, array<string, mixed>> $attrs
     * @return array<string, mixed>
     */
    private static function createMockSchema(array $attrs, bool $timestamps = true): array
    {
        return [
            'options' => ['timestamps' => $timestamps ? ['createdAt', 'updatedAt'] : false],
            'attributes' => [
                'id' => ['type' => 'integer'],
                ...$attrs,
                ...($timestamps ? ['createdAt' => ['type' => 'timestamp'], 'updatedAt' => ['type' => 'timestamp']] : []),
            ],
        ];
    }

    public function testTheIdAttributeIsAlwaysSortable(): void
    {
        self::assertTrue(Attributes::isSortable(self::createMockSchema([]), 'id'));
    }

    public function testTimestampsAreSortable(): void
    {
        self::assertTrue(Attributes::isSortable(self::createMockSchema([], true), 'createdAt'));
        self::assertTrue(Attributes::isSortable(self::createMockSchema([], true), 'updatedAt'));
        self::assertFalse(Attributes::isSortable(self::createMockSchema([], false), 'createdAt'));
    }

    public function testComponentFieldsAreNotSortable(): void
    {
        self::assertFalse(Attributes::isSortable(self::createMockSchema(['someComponent' => ['type' => 'component']]), 'someComponent'));
    }

    public function testJsonFieldsAreNotSortable(): void
    {
        self::assertFalse(Attributes::isSortable(self::createMockSchema(['jsonInput' => ['type' => 'json']]), 'jsonInput'));
    }

    public function testXToOneRelationsOnlyAreSortable(): void
    {
        $relationTypes = [
            'oneWayRel' => 'oneToOne',
            'manyToOneRel' => 'manyToOne',
            'oneToOneRel' => 'oneToOne',
            'manyWayRel' => 'oneToMany',
            'oneToManyRel' => 'oneToMany',
            'manyToManyRel' => 'manyToMany',
            'manyToManyMorphRel' => 'manyToManyMorph',
            'manyToOneMorphRel' => 'manyToOneMorph',
            'oneToManyMorphRel' => 'oneToManyMorph',
            'oneToOneMorphRel' => 'oneToOneMorph',
            'oneMorphToOneRel' => 'oneMorphToOne',
            'manyMorphToOneRel' => 'manyMorphToOne',
            'manyMorphToManyRel' => 'manyMorphToMany',
        ];
        $schema = self::createMockSchema(array_map(
            static fn (string $relationType): array => ['type' => 'relation', 'targetModel' => 'someModel', 'relationType' => $relationType],
            $relationTypes,
        ));

        self::assertTrue(Attributes::isSortable($schema, 'oneWayRel'));
        self::assertTrue(Attributes::isSortable($schema, 'manyToOneRel'));
        self::assertTrue(Attributes::isSortable($schema, 'oneToOneRel'));

        foreach (array_slice(array_keys($relationTypes), 3) as $name) {
            self::assertFalse(Attributes::isSortable($schema, $name), $name);
        }
    }

    public function testIsVisibleChecksIfTheAttributeIsInAModelAttributes(): void
    {
        self::assertTrue(Attributes::isVisible(self::createMockSchema(['field' => ['type' => 'string']]), 'field'));
        self::assertFalse(Attributes::isVisible(self::createMockSchema([]), 'createdAt'));
    }
}
