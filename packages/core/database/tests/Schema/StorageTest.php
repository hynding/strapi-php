<?php

declare(strict_types=1);

namespace Strapi\Database\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Strapi\Database\Schema\Storage;
use Strapi\Database\Tests\Support\GetstartedDatabase;

/**
 * `hashSchema()` must order tables as upstream's `localeCompare` does (ICU collation), or a
 * database shared with Node Strapi gets a different hash from each runtime. fixtures/schema-hash-order.json
 * was produced with Node: the names, their `localeCompare` order and the sha256 upstream computes.
 */
final class StorageTest extends TestCase
{
    /** @return array{names: list<string>, sorted: list<string>, hash: string} */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../fixtures/schema-hash-order.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testCompareNamesFollowsIcuCollation(): void
    {
        ['names' => $names, 'sorted' => $sorted] = self::fixture();

        if (class_exists(\Collator::class)) {
            $withIntl = $names;
            usort($withIntl, static fn (string $a, string $b): int => Storage::compareNames($a, $b));
            self::assertSame($sorted, $withIntl, 'ext-intl order');
        }

        $fallback = $names;
        usort($fallback, static fn (string $a, string $b): int => Storage::compareNames($a, $b, false));
        self::assertSame($sorted, $fallback, 'ASCII fallback order');
    }

    public function testHashMatchesUpstreamForTheSameTables(): void
    {
        ['names' => $names, 'hash' => $hash] = self::fixture();

        $column = ['name' => 'id', 'type' => 'increments', 'args' => [['primary' => true, 'primaryKey' => true]], 'defaultTo' => null, 'notNullable' => true, 'unsigned' => false];
        $schema = ['tables' => array_map(static fn (string $name): array => ['name' => $name, 'columns' => [$column], 'indexes' => [], 'foreignKeys' => []], $names)];

        self::assertSame($hash, GetstartedDatabase::create()->schema->schemaStorage->hashSchema($schema));
    }
}
