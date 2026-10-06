<?php

declare(strict_types=1);

namespace Strapi\Database\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Strapi\Database\Tests\Support\GetstartedDatabase;

/**
 * Byte-for-byte parity with Node Strapi: fixtures/getstarted-schema.json was produced by running
 * upstream `transformContentTypesToModels` + `createMetadata` + `metadataToSchema` (5.56.0) over the
 * same getstarted schemas (and the same built-in admin/upload/users-permissions models).
 */
final class SchemaParityTest extends TestCase
{
    public function testTargetSchemaMatchesNodeStrapi(): void
    {
        $expected = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/getstarted-schema.json'), true, 512, JSON_THROW_ON_ERROR);
        $expectedMeta = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/getstarted-metadata.json'), true, 512, JSON_THROW_ON_ERROR);

        $db = GetstartedDatabase::create();
        $actual = $db->schema->getSchema();

        $byName = static function (array $schema): array {
            $out = [];
            foreach ($schema['tables'] as $table) {
                $out[$table['name']] = $table;
            }
            ksort($out);

            return $out;
        };

        $expectedTables = $byName($expected);
        $actualTables = $byName($actual);

        self::assertSame(array_keys($expectedTables), array_keys($actualTables), 'same table names');

        foreach ($expectedTables as $name => $table) {
            self::assertEquals($table, $actualTables[$name], "table {$name}");
        }

        foreach ($expectedMeta as $uid => $meta) {
            self::assertTrue($db->metadata->has($uid), "model {$uid}");
            self::assertSame($meta['tableName'], $db->metadata->get($uid)['tableName']);
            self::assertEquals($meta['columnToAttribute'], $db->metadata->get($uid)['columnToAttribute'], "columns of {$uid}");
        }

        // and the hash upstream would store is computed over an identical JSON document
        self::assertSame(
            hash('sha256', self::nodeStringify($expected)),
            $db->schema->schemaStorage->hashSchema($actual),
        );
    }

    /** JSON.stringify of the schema with tables sorted by name, as upstream hashSchema does. */
    private static function nodeStringify(array $schema): string
    {
        usort($schema['tables'], static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
