<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\DocumentService;

use Strapi\Core\Services\DocumentService\Utils\CloneRelations;
use Strapi\Core\Tests\BootedAppTestCase;
use Strapi\Database\Utils\SchemaFactory;
use Strapi\Types\Schema\Schema;

/**
 * `prepareCloneData` (utils/clone-relations.ts has no upstream unit test; these follow the cases of
 * tests/api core/strapi/document-service/relations/clone-*relation-operations).
 */
final class CloneRelationsTest extends BootedAppTestCase
{
    private static function product(): Schema
    {
        return SchemaFactory::contentType([
            'kind' => 'collectionType',
            'collectionName' => 'products',
            'info' => ['displayName' => 'Product', 'singularName' => 'product', 'pluralName' => 'products'],
            'options' => ['draftAndPublish' => true],
            'attributes' => [
                'name' => ['type' => 'string'],
                'tag' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'api::tag.tag', 'inversedBy' => 'product'],
                'legacyTag' => ['type' => 'relation', 'relation' => 'manyToOne', 'target' => 'api::tag.tag', 'useJoinTable' => false],
                'details' => ['type' => 'component', 'component' => 'default.details', 'repeatable' => false],
            ],
        ], 'api::product.product');
    }

    /** @return array{data: array<string, mixed>, relationsToCopy: list<string>} */
    private static function prepare(array $original, ?array $submitted): array
    {
        $details = SchemaFactory::component([
            'collectionName' => 'components_default_details',
            'info' => ['displayName' => 'details'],
            'attributes' => [
                'label' => ['type' => 'string'],
                'tag' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'api::tag.tag'],
            ],
        ], 'default.details');

        return CloneRelations::prepareCloneData(self::strapi(), $original, $submitted, self::product(), static fn (string $uid): ?Schema => $uid === 'default.details' ? $details : null);
    }

    public function testUnchangedJoinTableRelationsAreCopiedNotRecreated(): void
    {
        $original = ['name' => 'Source', 'tag' => ['id' => 7]];

        foreach ([['name' => 'Clone'], ['name' => 'Clone', 'tag' => ['connect' => [], 'disconnect' => []]]] as $submitted) {
            $result = self::prepare($original, $submitted);
            self::assertSame(['tag'], $result['relationsToCopy']);
            self::assertSame(['name' => 'Clone'], $result['data']);
        }
    }

    public function testMeaningfulOperationsReplaceTheRelation(): void
    {
        $operations = ['connect' => [['documentId' => 'other']], 'disconnect' => []];
        $result = self::prepare(['tag' => ['id' => 7]], ['tag' => $operations]);

        self::assertSame([], $result['relationsToCopy']);
        self::assertSame(['tag' => $operations], $result['data']);
    }

    public function testJoinColumnRelationsKeepTheMergedValue(): void
    {
        // upstream does not transform operations on useJoinTable: false relations: the original id wins
        $result = self::prepare(['legacyTag' => ['id' => 3]], ['legacyTag' => ['connect' => [], 'disconnect' => [['documentId' => 'x']]]]);

        self::assertSame(['id' => 3, 'connect' => [], 'disconnect' => [['documentId' => 'x']]], $result['data']['legacyTag']);
    }

    public function testComponentsAreMergedWithoutTheirIdsAndKeepNestedRelations(): void
    {
        $original = ['details' => ['id' => 11, 'label' => 'Source details', 'tag' => ['id' => 7]]];

        $empty = self::prepare($original, ['details' => ['label' => 'Cloned details', 'tag' => ['connect' => [], 'disconnect' => []]]]);
        self::assertSame(['label' => 'Cloned details', 'tag' => ['id' => 7, 'connect' => [], 'disconnect' => []]], $empty['data']['details']);

        $operations = ['connect' => [['documentId' => 'other']]];
        $connect = self::prepare($original, ['details' => ['tag' => $operations]]);
        self::assertSame(['label' => 'Source details', 'tag' => $operations], $connect['data']['details']);
    }
}
