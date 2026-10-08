<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests\Services\SchemaBuilder;

use PHPUnit\Framework\TestCase;
use Strapi\ContentTypeBuilder\Services\SchemaBuilder\SchemaBuilder;
use Strapi\ContentTypeBuilder\Services\SchemaBuilder\SchemaHandler;
use Strapi\ContentTypeBuilder\Tests\StubStrapi;

require_once __DIR__ . '/../../StubStrapi.php';

/** Port of server/src/services/schema-builder/__tests__/content-type-builder.vitest.test.ts. */
final class ContentTypeBuilderTest extends TestCase
{
    private const string ARTICLE = 'api::article.article';
    private const string CATEGORY = 'api::category.category';

    /** @return array{SchemaBuilder, SchemaHandler, SchemaHandler} */
    private static function builder(): array
    {
        $article = SchemaHandler::createSchemaHandler([
            'uid' => self::ARTICLE,
            'dir' => '/tmp',
            'filename' => 'schema.json',
            'schema' => [
                'kind' => 'collectionType',
                'collectionName' => 'articles',
                'info' => ['singularName' => 'article', 'pluralName' => 'articles', 'displayName' => 'Article'],
                'options' => [],
                'attributes' => [
                    'categories' => ['type' => 'relation', 'relation' => 'manyToMany', 'target' => self::CATEGORY, 'inversedBy' => 'articles', 'required' => true],
                ],
            ],
        ]);

        $category = SchemaHandler::createSchemaHandler([
            'uid' => self::CATEGORY,
            'dir' => '/tmp',
            'filename' => 'schema.json',
            'schema' => [
                'kind' => 'collectionType',
                'collectionName' => 'categories',
                'info' => ['singularName' => 'category', 'pluralName' => 'categories', 'displayName' => 'Category'],
                'options' => [],
                'attributes' => [
                    'articles' => ['type' => 'relation', 'relation' => 'manyToMany', 'target' => self::ARTICLE, 'mappedBy' => 'categories'],
                ],
            ],
        ]);

        $builder = new SchemaBuilder(StubStrapi::create());
        $builder->contentTypes = [self::ARTICLE => $article, self::CATEGORY => $category];

        return [$builder, $article, $category];
    }

    public function testPreservesRequiredOnTheTargetAttributeWhenRegeneratingABidirectionalInverse(): void
    {
        [$builder, $article] = self::builder();

        // When both sides are in the payload, editing Category regenerates Article.categories
        $builder->setRelation([
            'key' => 'articles',
            'uid' => self::CATEGORY,
            'attribute' => ['type' => 'relation', 'relation' => 'manyToMany', 'target' => self::ARTICLE, 'targetAttribute' => 'categories', 'dominant' => false],
        ]);

        self::assertSame([
            'type' => 'relation',
            'relation' => 'manyToMany',
            'target' => self::CATEGORY,
            'required' => true,
            'inversedBy' => 'articles',
        ], $article->getAttribute('categories'));
    }

    public function testDoesNotInheritRequiredOntoTheInverseFromTheSourceAttribute(): void
    {
        [$builder, , $category] = self::builder();

        $builder->setRelation([
            'key' => 'categories',
            'uid' => self::ARTICLE,
            'attribute' => ['type' => 'relation', 'relation' => 'manyToMany', 'target' => self::CATEGORY, 'targetAttribute' => 'articles', 'dominant' => true, 'required' => true],
        ]);

        self::assertArrayNotHasKey('required', $category->getAttribute('articles'));
    }
}
