<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Services\Utils;

require_once __DIR__ . '/../../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Services\Utils\Populate;
use Strapi\ContentManager\Tests\StubStrapi;
use Strapi\Core\Strapi;

/** Port of server/src/services/utils/__tests__/query-populate.test.ts. */
final class QueryPopulateTest extends TestCase
{
    private const string UID = 'api::model.model';

    private Strapi $strapi;

    protected function setUp(): void
    {
        $this->strapi = StubStrapi::create();
        StubStrapi::addContentTypes($this->strapi, [
            'empty' => ['attributes' => []],
            self::UID => ['attributes' => [
                'field' => ['type' => 'string'],
                'relation' => ['type' => 'relation', 'relation' => 'oneToMany', 'target' => self::UID],
                // Edge case: an attribute named "populate" should be populated
                'populate' => ['type' => 'relation', 'relation' => 'oneToMany', 'target' => self::UID],
                'component' => ['type' => 'component', 'component' => 'component'],
                'repeatableComponent' => ['type' => 'component', 'repeatable' => true, 'component' => 'component'],
                'media' => ['type' => 'media'],
            ]],
        ]);
        StubStrapi::addComponents($this->strapi, [
            'component' => ['attributes' => [
                'field' => ['type' => 'string'],
                'compoRelation' => ['type' => 'relation', 'relation' => 'oneToMany', 'target' => self::UID],
            ]],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $conditions
     * @return array<string, mixed>
     */
    private static function getFilterQuery(array $conditions): array
    {
        return ['filters' => ['$or' => [['$and' => [['$or' => $conditions]]]]]];
    }

    public function testTopLevelFieldShouldNotBePopulated(): void
    {
        $result = Populate::getQueryPopulate($this->strapi, self::UID, self::getFilterQuery([['field' => ['$exists' => true]]]));

        self::assertSame([], $result);
    }

    public function testOneRelationalFieldShouldBePopulated(): void
    {
        $result = Populate::getQueryPopulate($this->strapi, self::UID, self::getFilterQuery([['relation' => ['field' => 'value']]]));

        self::assertSame(['relation' => []], $result);
    }

    public function testOneRelationalFieldNamedPopulateShouldBePopulated(): void
    {
        $result = Populate::getQueryPopulate($this->strapi, self::UID, self::getFilterQuery([['populate' => ['populate' => ['field' => 'value']]]]));

        // Populate train! Choo choo!
        self::assertSame(['populate' => ['populate' => ['populate' => []]]], $result);
    }

    public function testRelationInComponentShouldBePopulated(): void
    {
        $result = Populate::getQueryPopulate($this->strapi, self::UID, self::getFilterQuery([['component' => ['compoRelation' => ['field' => 'value']]]]));

        self::assertSame(['component' => ['populate' => ['compoRelation' => []]]], $result);
    }

    public function testRelationInRepeatableComponentShouldBePopulated(): void
    {
        $result = Populate::getQueryPopulate($this->strapi, self::UID, self::getFilterQuery([['repeatableComponent' => ['compoRelation' => ['field' => 'value']]]]));

        self::assertSame(['repeatableComponent' => ['populate' => ['compoRelation' => []]]], $result);
    }

    public function testNestedPopulateIsNotDroppedByAShallowerSiblingCondition(): void
    {
        $result = Populate::getQueryPopulate($this->strapi, self::UID, [
            'filters' => [
                '$or' => [
                    ['relation' => ['component' => ['field' => ['$eq' => 'value']]]],
                    ['$and' => [['$or' => [['relation' => ['field' => 'value']]]]]],
                ],
            ],
        ]);

        self::assertSame(['relation' => ['populate' => ['component' => []]]], $result);
    }

    public function testPopulateMultipleFieldsAtOnce(): void
    {
        $result = Populate::getQueryPopulate($this->strapi, self::UID, self::getFilterQuery([
            ['relation' => ['component' => ['field' => ['$eq' => 'value']]]],
            ['relation' => ['field' => 'value']],
            ['repeatableComponent' => ['$elemMatch' => ['compoRelation' => ['field' => 'value']]]],
        ]));

        self::assertEquals([
            'relation' => ['populate' => ['component' => []]],
            'repeatableComponent' => ['populate' => ['compoRelation' => []]],
        ], $result);
    }
}
