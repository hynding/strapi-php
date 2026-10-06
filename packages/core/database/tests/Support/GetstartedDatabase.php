<?php

declare(strict_types=1);

namespace Strapi\Database\Tests\Support;

use Strapi\Database\Database;
use Strapi\Database\Utils\SchemaFactory;
use Strapi\Types\Schema\Schema;

/**
 * Boots an in-memory SQLite Database loaded with the upstream getstarted example schemas
 * (tests/fixtures/getstarted) plus the built-in admin/upload models.
 */
final class GetstartedDatabase
{
    /** @return array<string, Schema> */
    public static function schemas(): array
    {
        $root = __DIR__ . '/../fixtures/getstarted';
        $schemas = SchemaFactory::builtinSchemas();

        foreach (glob($root . '/api/*/content-types/*/schema.json') ?: [] as $file) {
            $schema = SchemaFactory::fromJsonFile($file);
            $schemas[$schema->uid] = self::convertCustomFields($schema);
        }
        foreach (glob($root . '/components/*/*.json') ?: [] as $file) {
            $schema = SchemaFactory::fromJsonFile($file);
            $schemas[$schema->uid] = self::convertCustomFields($schema);
        }

        return $schemas;
    }

    /** core's convertCustomFieldType(): the only custom field in getstarted is the color picker (a string). */
    private static function convertCustomFields(Schema $schema): Schema
    {
        $attributes = $schema->attributes;
        $changed = false;
        foreach ($attributes as $name => $attribute) {
            if (($attribute['type'] ?? null) === 'customField') {
                $attributes[$name]['type'] = 'string';
                $changed = true;
            }
        }

        if (!$changed) {
            return $schema;
        }

        return new Schema(
            $schema->uid, $schema->modelType, $schema->kind, $schema->modelName, $schema->globalId, $schema->collectionName,
            $schema->plugin, $schema->apiName, $schema->category, $schema->info, $schema->options, $schema->pluginOptions, $attributes, $schema->config,
        );
    }

    /** @param array<string, mixed> $settings */
    public static function create(array $settings = [], ?array $schemas = null): Database
    {
        $db = new Database([
            'connection' => [
                'client' => 'sqlite',
                'connection' => ['filename' => ':memory:'],
                'useNullAsDefault' => true,
            ],
            'settings' => ['forceMigration' => true, 'runMigrations' => true, 'migrations' => ['dir' => ''], ...$settings],
        ]);

        $models = $schemas ?? self::schemas();
        $db->init($models);
        $db->metadata->add(SchemaFactory::coreStoreModel());
        $db->metadata->loadModels([]);
        $db->schema->invalidate();

        return $db;
    }
}
