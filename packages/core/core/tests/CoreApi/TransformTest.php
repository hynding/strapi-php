<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\CoreApi;

use Strapi\Core\CoreApi\Controller\Transform;
use Strapi\Core\Tests\BootedAppTestCase;
use Strapi\Database\Utils\SchemaFactory;
use Strapi\Types\Schema\Schema;

/** Port of packages/core/core/src/core-api/controller/__tests__/transform.test.ts. */
final class TransformTest extends BootedAppTestCase
{
    /** @param array<string, array<string, mixed>> $attributes */
    private static function contentType(array $attributes): Schema
    {
        return SchemaFactory::contentType([
            'kind' => 'collectionType',
            'collectionName' => 'tests',
            'info' => ['displayName' => 'test', 'singularName' => 'test', 'pluralName' => 'tests'],
            'attributes' => $attributes,
        ], 'api::test.test');
    }

    private static function meta(?array $result): mixed
    {
        return $result['meta'] ?? null;
    }

    public function testV4UsingJsonApiFormat(): void
    {
        $contentType = self::contentType([
            'title' => ['type' => 'string'],
            'relation' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'api::category.category'],
            'media' => ['type' => 'media'],
            'multiMedia' => ['type' => 'media', 'multiple' => true],
            'repeatableCompo' => ['type' => 'component', 'repeatable' => true, 'component' => 'basic.simple'],
            'compo' => ['type' => 'component', 'component' => 'basic.simple'],
            'dz' => ['type' => 'dynamiczone', 'components' => ['basic.simple']],
        ]);

        $result = Transform::transformResponse(self::strapi(), [
            'id' => 1,
            'documentId' => 'abcd',
            'title' => 'Hello',
            'relation' => ['id' => 1, 'documentId' => 'abcd', 'value' => 'test'],
            'media' => ['id' => 1, 'documentId' => 'abcd', 'value' => 'test'],
            'multiMedia' => [['id' => 1, 'documentId' => 'abcd', 'value' => 'test']],
            'repeatableCompo' => [['id' => 1, 'name' => 'test']],
            'compo' => ['id' => 1, 'name' => 'test'],
            'dz' => [['id' => 2, 'name' => 'test', '__component' => 'basic.simple']],
        ], [], ['contentType' => $contentType, 'useJsonAPIFormat' => true]);

        self::assertSame([
            'id' => 1,
            'documentId' => 'abcd',
            'attributes' => [
                'title' => 'Hello',
                'relation' => ['data' => ['id' => 1, 'documentId' => 'abcd', 'attributes' => ['value' => 'test']]],
                'media' => ['data' => ['id' => 1, 'documentId' => 'abcd', 'attributes' => ['value' => 'test']]],
                'multiMedia' => ['data' => [['id' => 1, 'documentId' => 'abcd', 'attributes' => ['value' => 'test']]]],
                'repeatableCompo' => [['id' => 1, 'name' => 'test']],
                'compo' => ['id' => 1, 'name' => 'test'],
                'dz' => [['id' => 2, 'name' => 'test', '__component' => 'basic.simple']],
            ],
        ], $result['data']);
        self::assertSame('{}', json_encode(self::meta($result)));
    }

    public function testLeavesNilValuesUntouched(): void
    {
        self::assertNull(Transform::transformResponse(self::strapi(), null));
    }

    public function testThrowsIfEntryIsNotAnObjectOrArray(): void
    {
        foreach ([0, 'azaz', new \DateTimeImmutable()] as $value) {
            try {
                Transform::transformResponse(self::strapi(), $value);
                self::fail('expected an exception');
            } catch (\RuntimeException $e) {
                self::assertSame('Entry must be an object or an array of objects', $e->getMessage());
            }
        }
    }

    public function testHandlesArraysOfEntriesAndSingleEntry(): void
    {
        $list = Transform::transformResponse(self::strapi(), [['id' => 1, 'title' => 'Hello']]);
        self::assertSame([['id' => 1, 'title' => 'Hello']], $list['data']);
        self::assertSame('{}', json_encode(self::meta($list)));

        $single = Transform::transformResponse(self::strapi(), ['id' => 1, 'title' => 'Hello']);
        self::assertSame(['id' => 1, 'title' => 'Hello'], $single['data']);
    }

    public function testAcceptsAnyMeta(): void
    {
        $result = Transform::transformResponse(self::strapi(), ['id' => 1, 'title' => 'Hello'], ['foo' => 'bar']);
        self::assertSame(['data' => ['id' => 1, 'title' => 'Hello'], 'meta' => ['foo' => 'bar']], $result);
    }

    public function testFlatFormatLeavesRelationsMediaAndComponentsAsIs(): void
    {
        $contentType = self::contentType([
            'relation' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'api::category.category'],
            'media' => ['type' => 'media'],
        ]);

        $entry = ['id' => 1, 'title' => 'Hello', 'relation' => [['id' => 1, 'value' => 'test', 'nested' => ['id' => 2, 'foo' => 'bar']]], 'media' => [['id' => 1, 'value' => 'test']]];
        $result = Transform::transformResponse(self::strapi(), $entry, [], ['contentType' => $contentType]);

        self::assertSame($entry, $result['data']);
    }

    public function testV4RelationsRecursively(): void
    {
        $contentType = self::contentType([
            'relation' => ['type' => 'relation', 'relation' => 'oneToMany', 'target' => 'api::article.article'],
        ]);

        $result = Transform::transformResponse(self::strapi(), [
            'id' => 1,
            'title' => 'Hello',
            'relation' => [['id' => 1, 'title' => 'test', 'categories' => [['id' => 2, 'name' => 'bar']]]],
        ], [], ['contentType' => $contentType, 'useJsonAPIFormat' => true]);

        self::assertSame([
            'id' => 1,
            'attributes' => [
                'title' => 'Hello',
                'relation' => ['data' => [[
                    'id' => 1,
                    'attributes' => ['title' => 'test', 'categories' => ['data' => [['id' => 2, 'attributes' => ['name' => 'bar']]]]],
                ]]],
            ],
        ], $result['data']);
    }
}
