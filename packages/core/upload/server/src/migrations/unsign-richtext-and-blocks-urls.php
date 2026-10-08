<?php

declare(strict_types=1);

namespace Strapi\Upload\Migrations;

use Doctrine\DBAL\Connection;
use Strapi\Core\Strapi;
use Strapi\Database\Database;
use Strapi\Types\Schema\Schema;
use Strapi\Upload\Provider as UploadProvider;
use Strapi\Upload\Services\Extensions\Utils;

/**
 * Port of server/src/migrations/unsign-richtext-and-blocks-urls.ts.
 *
 * Richtext and blocks attributes embed the file URL in their own value, so a
 * signed URL written by the admin used to be frozen in the row and broke as
 * soon as the signature expired. New writes are normalised by the upload
 * document service middleware, and the read path re-signs a stored URL that
 * still carries a stale signature, so this migration is a cleanup of the rows
 * written before the fix rather than a prerequisite for it.
 *
 * Idempotent: re-running it finds nothing left to change. {@see self::migration()} is the
 * migration array `strapi.db.migrations.internal.register()` takes (upstream reads the ambient
 * `strapi`; here the instance is bound when the migration is built).
 *
 * @phpstan-type TargetAttribute array{name: string, type: 'richtext'|'blocks'}
 * @phpstan-type Progress array{scanned: int, updated: int}
 */
final class UnsignRichtextAndBlocksUrls
{
    private const int BATCH_SIZE = 100;
    private const string LOG_PREFIX = '[unsign-richtext-and-blocks-urls]';

    public const string NAME = 'upload::unsign-richtext-and-blocks-urls';

    /** @return array{name: string, up: \Closure(Connection, Database): void, down: \Closure(): never} */
    public static function migration(Strapi $strapi): array
    {
        return [
            'name' => self::NAME,
            'up' => static function (Connection $trx, Database $db) use ($strapi): void {
                self::migrateUp($strapi, $trx, $db);
            },
            'down' => static function (): never {
                throw new \RuntimeException('not implemented');
            },
        ];
    }

    /** @return list<TargetAttribute> */
    private static function getTargetAttributes(Schema $schema): array
    {
        $targets = [];
        foreach ($schema->attributes as $name => $attribute) {
            $type = $attribute['type'] ?? null;
            if ($type === 'richtext' || $type === 'blocks') {
                $targets[] = ['name' => (string) $name, 'type' => $type];
            }
        }

        return $targets;
    }

    /** @param \ArrayObject<string, string|null> $cache */
    private static function unsignValue(Strapi $strapi, mixed $value, string $type, \ArrayObject $cache): mixed
    {
        return $type === 'blocks'
            ? Utils::mapBlocksImages($value, static fn (array $image): array => Utils::unsignImage($strapi, $image, $cache))
            : Utils::mapRichtextUrls($value, static fn (string $url): string => Utils::stripSignedUrl($strapi, $url, $cache));
    }

    /**
     * @param \ArrayObject<string, string|null> $cache
     * @return Progress
     */
    private static function migrateSchema(Strapi $strapi, Connection $trx, Database $db, Schema $schema, \ArrayObject $cache): array
    {
        $progress = ['scanned' => 0, 'updated' => 0];
        $attributes = self::getTargetAttributes($schema);

        if ($attributes === []) {
            return $progress;
        }

        $uid = $schema->uid;

        if (!$db->metadata->has($uid)) {
            return $progress;
        }

        $meta = $db->metadata($uid);
        $tableName = $meta['tableName'];
        // The schema checks must run on the migration transaction
        $schemaManager = $db->getSchemaConnection($trx);
        $table = $db->getSchemaName() !== null ? $db->getSchemaName() . '.' . $tableName : $tableName;

        // On a fresh project the migrations run before the tables exist
        if (!$schemaManager->tablesExist([$table])) {
            return $progress;
        }

        $columns = array_map(static fn ($column): string => strtolower($column->getName()), $schemaManager->listTableColumns($table));

        // Internal migrations run before the schema sync: an attribute added in the
        // same deploy as this version has no column yet, so only select what exists
        $existing = [];

        foreach ($attributes as $attribute) {
            $attributeMeta = $meta['attributes'][$attribute['name']] ?? null;
            $columnName = is_array($attributeMeta) && !empty($attributeMeta['columnName']) ? $attributeMeta['columnName'] : $attribute['name'];

            if (in_array(strtolower((string) $columnName), $columns, true)) {
                $existing[] = $attribute;
            }
        }

        if ($existing === []) {
            return $progress;
        }

        $select = ['id', ...array_map(static fn (array $a): string => $a['name'], $existing)];

        $offset = 0;
        $hasMore = true;

        while ($hasMore) {
            $rows = $strapi->db()->query($uid)->findMany([
                'select' => $select,
                'limit' => self::BATCH_SIZE,
                'offset' => $offset,
                'orderBy' => ['id' => 'asc'],
            ]);

            $hasMore = count($rows) === self::BATCH_SIZE;
            $offset += count($rows);
            $progress['scanned'] += count($rows);

            foreach ($rows as $row) {
                $data = [];

                foreach ($existing as ['name' => $name, 'type' => $type]) {
                    // A value the mapper cannot process is logged and skipped.
                    try {
                        $unsigned = self::unsignValue($strapi, $row[$name] ?? null, $type, $cache);

                        if ($unsigned !== ($row[$name] ?? null)) {
                            $data[$name] = $unsigned;
                        }
                    } catch (\Throwable $error) {
                        $strapi->log()->warning(self::LOG_PREFIX . " Skipped {$uid}.{$name} on row {$row['id']}: " . $error->getMessage());
                    }
                }

                if ($data !== []) {
                    $strapi->db()->query($uid)->update(['where' => ['id' => $row['id']], 'data' => $data]);
                    $progress['updated'] += 1;
                }
            }
        }

        if ($progress['scanned'] > 0) {
            $strapi->log()->info(self::LOG_PREFIX . " {$uid}: {$progress['scanned']} rows scanned, {$progress['updated']} updated");
        }

        return $progress;
    }

    private static function migrateUp(Strapi $strapi, Connection $trx, Database $db): void
    {
        $provider = $strapi->hasPlugin('upload') ? $strapi->plugin('upload')->provider : null;

        // Nothing is signed on a public provider, so there is nothing to strip
        if (!$provider instanceof UploadProvider || !$provider->isPrivate()) {
            return;
        }

        $schemas = [
            ...array_values($strapi->contentTypes()),
            ...array_values($strapi->components()),
        ];

        // One presign per distinct URL for the whole run, however many rows it appears in
        $cache = Utils::createSignCache();
        $total = ['scanned' => 0, 'updated' => 0];

        foreach ($schemas as $schema) {
            $progress = self::migrateSchema($strapi, $trx, $db, $schema, $cache);

            $total['scanned'] += $progress['scanned'];
            $total['updated'] += $progress['updated'];
        }

        $strapi->log()->info(self::LOG_PREFIX . " Done: {$total['scanned']} rows scanned, {$total['updated']} updated");
    }
}
