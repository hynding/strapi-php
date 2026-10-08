<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\CoreApi\Routes\Validation;

use PHPUnit\Framework\TestCase;
use Strapi\Core\CoreApi\Routes\Validation\SchemaRegistry;
use Strapi\Utils\Zod as z;

/** Port of packages/core/core/src/core-api/routes/validation/__tests__/schema-registry.test.ts. */
final class SchemaRegistryTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function toJsonSchemas(SchemaRegistry $schemaStore): array
    {
        $registry = z::registry();

        foreach ($schemaStore->entries() as $id => $schema) {
            $registry->add($schema, ['id' => $id]);
        }

        return z::toJSONSchema($registry, [
            'target' => 'draft-2020-12',
            'io' => 'output',
            'uri' => static fn (string $id): string => "#/components/schemas/{$id}",
        ])['schemas'];
    }

    public function testSetsGetsFindsAndEnumeratesSchemasById(): void
    {
        $schemaStore = SchemaRegistry::createContentAPISchemaRegistry();
        $schema = z::string();

        $schemaStore->set('Article', $schema);

        self::assertTrue($schemaStore->has('Article'));
        self::assertSame($schema, $schemaStore->get('Article'));
        self::assertSame(['Article' => $schema], $schemaStore->entries());
    }

    public function testReplacesAnExistingSchemaInTheIdMap(): void
    {
        $schemaStore = SchemaRegistry::createContentAPISchemaRegistry();
        $placeholder = z::any();
        $schema = z::object(['title' => z::string()]);

        $schemaStore->set('Article', $placeholder);
        $schemaStore->set('Article', $schema);

        self::assertSame($schema, $schemaStore->get('Article'));
    }

    public function testRemovesSchemasFromThisInstanceOnly(): void
    {
        $schemaStore = SchemaRegistry::createContentAPISchemaRegistry();
        $schemaStore->set('Article', z::string());

        self::assertTrue($schemaStore->remove('Article'));
        self::assertFalse($schemaStore->remove('Article'));
        self::assertFalse($schemaStore->has('Article'));
    }

    public function testClearsThisInstanceWithoutAffectingAnotherFactoryInstance(): void
    {
        $schemaStore = SchemaRegistry::createContentAPISchemaRegistry();
        $otherStore = SchemaRegistry::createContentAPISchemaRegistry();
        $article = z::string();
        $category = z::number();

        $schemaStore->set('Article', $article);
        $otherStore->set('Category', $category);

        $schemaStore->clear();

        self::assertSame([], $schemaStore->entries());
        self::assertSame($category, $otherStore->get('Category'));
    }

    public function testDoesNotShareStateAcrossFactoryInstances(): void
    {
        $schemaStore = SchemaRegistry::createContentAPISchemaRegistry();
        $otherStore = SchemaRegistry::createContentAPISchemaRegistry();

        $schemaStore->set('Article', z::string());

        self::assertFalse($otherStore->has('Article'));
    }

    public function testDefersInProgressLookupsSoJsonSchemaKeepsComponentRefs(): void
    {
        $schemaStore = SchemaRegistry::createContentAPISchemaRegistry();

        $schemaStore->startPending('Article');
        $schemaStore->set('Article', z::any());

        $articleRef = $schemaStore->getOrDefer('Article');
        self::assertNotNull($articleRef, 'expected a deferred Article schema');

        $schemaStore->set('Article', z::object([
            'title' => z::string(),
            'related' => z::array($articleRef),
        ]));
        $schemaStore->finishPending('Article');

        self::assertSame(
            ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Article']],
            self::toJsonSchemas($schemaStore)['Article']['properties']['related'],
        );
    }

    public function testConvertsTheReplacementSchemaInsteadOfAStaleCyclicalPlaceholder(): void
    {
        $schemaStore = SchemaRegistry::createContentAPISchemaRegistry();

        $schemaStore->set('Article', z::any());
        $schemaStore->set('Article', z::object(['title' => z::string()]));

        $article = self::toJsonSchemas($schemaStore)['Article'];
        self::assertSame('object', $article['type']);
        self::assertSame(['title' => ['type' => 'string']], $article['properties']);
        self::assertSame(['title'], $article['required']);
        self::assertFalse($article['additionalProperties']);
    }
}
