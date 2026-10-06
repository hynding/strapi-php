<?php

declare(strict_types=1);

namespace Strapi\Database\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Database\Tests\Support\GetstartedDatabase;

final class SchemaSyncTest extends TestCase
{
    public function testSyncCreatesUpstreamTableNames(): void
    {
        $db = GetstartedDatabase::create();
        self::assertSame('CHANGED', $db->schema->sync());

        $tables = $db->dialect->schemaInspector->getTables();
        sort($tables);

        foreach ([
            'addresses', 'addresses_cmps', 'addresses_categories_lnk', 'articles', 'articles_categories_lnk', 'categories',
            'kitchensinks', 'kitchensinks_cmps', 'kitchensinks_one_way_tag_lnk', 'kitchensinks_one_to_one_tag_lnk',
            'kitchensinks_many_to_one_tag_lnk', 'kitchensinks_many_to_many_tags_lnk', 'kitchensinks_many_way_tags_lnk',
            'kitchensinks_morph_to_many_mph', 'temps', 'temps_category_lnk', 'temps_categories_lnk', 'tags',
            'components_basic_simples', 'components_blog_test_comos', 'components_dishes', 'components_dishes_categories_lnk',
            'files', 'files_related_mph', 'files_folder_lnk', 'upload_folders', 'upload_folders_parent_lnk', 'admin_users',
            'strapi_database_schema', 'strapi_migrations', 'strapi_migrations_internal', 'strapi_core_store_settings',
        ] as $expected) {
            self::assertContains($expected, $tables, "table {$expected}");
        }

        $columns = array_map(static fn (array $c): string => $c['name'], $db->dialect->schemaInspector->getColumns('articles'));
        self::assertSame(
            ['id', 'document_id', 'title', 'blocks_content', 'markdown_content', 'author_name', 'slug', 'created_at', 'updated_at', 'published_at', 'created_by_id', 'updated_by_id', 'locale'],
            $columns,
        );

        $linkColumns = array_map(static fn (array $c): string => $c['name'], $db->dialect->schemaInspector->getColumns('articles_categories_lnk'));
        self::assertSame(['id', 'article_id', 'category_id', 'category_ord', 'article_ord'], $linkColumns);

        $cmps = array_map(static fn (array $c): string => $c['name'], $db->dialect->schemaInspector->getColumns('kitchensinks_cmps'));
        self::assertSame(['id', 'entity_id', 'cmp_id', 'component_type', 'field', 'order'], $cmps);

        $mph = array_map(static fn (array $c): string => $c['name'], $db->dialect->schemaInspector->getColumns('files_related_mph'));
        self::assertSame(['id', 'file_id', 'related_id', 'related_type', 'field', 'order'], $mph);

        $tagColumns = array_map(static fn (array $c): string => $c['name'], $db->dialect->schemaInspector->getColumns('tags'));
        self::assertContains('taggable_id', $tagColumns);
        self::assertContains('taggable_type', $tagColumns);

        $indexes = array_map(static fn (array $i): string => $i['name'], $db->dialect->schemaInspector->getIndexes('articles_categories_lnk'));
        sort($indexes);
        self::assertSame(['articles_categories_lnk_fk', 'articles_categories_lnk_ifk', 'articles_categories_lnk_ofk', 'articles_categories_lnk_oifk', 'articles_categories_lnk_uq'], $indexes);

        $articleIndexes = array_map(static fn (array $i): string => $i['name'], $db->dialect->schemaInspector->getIndexes('articles'));
        self::assertContains('articles_documents_idx', $articleIndexes);
        self::assertContains('articles_created_by_id_fk', $articleIndexes);
    }

    public function testResyncIsNoopAndAddingAttributeAddsColumn(): void
    {
        $db = GetstartedDatabase::create();
        self::assertSame('CHANGED', $db->schema->sync());
        self::assertSame('UNCHANGED', $db->schema->sync());
        self::assertSame('UNCHANGED', $db->schema->syncSchema());

        // add an attribute
        $schemas = GetstartedDatabase::schemas();
        $tag = $schemas['api::tag.tag'];
        $attributes = $tag->attributes;
        $attributes['color'] = ['type' => 'string'];
        $schemas['api::tag.tag'] = new \Strapi\Types\Schema\Schema(
            $tag->uid, $tag->modelType, $tag->kind, $tag->modelName, $tag->globalId, $tag->collectionName,
            $tag->plugin, $tag->apiName, $tag->category, $tag->info, $tag->options, $tag->pluginOptions, $attributes, $tag->config,
        );

        $db->metadata = \Strapi\Database\Metadata\Metadata::create([]);
        $db->init($schemas);
        $db->metadata->add(\Strapi\Database\Utils\SchemaFactory::coreStoreModel());
        $db->metadata->loadModels([]);
        $db->schema->invalidate();

        self::assertSame('CHANGED', $db->schema->sync());
        $tagColumns = array_map(static fn (array $c): string => $c['name'], $db->dialect->schemaInspector->getColumns('tags'));
        self::assertContains('color', $tagColumns);
        self::assertSame('UNCHANGED', $db->schema->sync());
    }
}
