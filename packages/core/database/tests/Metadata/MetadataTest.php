<?php

declare(strict_types=1);

namespace Strapi\Database\Tests\Metadata;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Database\Metadata\Metadata;
use Strapi\Database\Utils\Identifiers\Identifiers;

/**
 * Port of metadata/__tests__/metadata.test.ts and metadata/__tests__/identifiers.test.ts.
 * The expected maps are upstream's resources/expected-*.ts dumped to JSON.
 */
final class MetadataTest extends TestCase
{
    private static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../fixtures/metadata/' . $name), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void
    {
        Identifiers::setGlobal(null);
    }

    /** @return iterable<array{string, array, array}> */
    public static function attributeConversions(): iterable
    {
        yield ['id', ['type' => 'increments'], ['type' => 'increments', 'columnName' => 'id']];
        yield ['documentId', ['type' => 'string'], ['type' => 'string', 'columnName' => 'document_id']];
        yield ['document_id', ['type' => 'string'], ['type' => 'string', 'columnName' => 'document_id']];
        yield ['action', ['type' => 'string', 'required' => true], ['type' => 'string', 'required' => true, 'columnName' => 'action']];
        yield ['actionParameters', ['type' => 'json', 'required' => false, 'default' => []], ['type' => 'json', 'required' => false, 'default' => [], 'columnName' => 'action_parameters']];
        yield ['createdAt', ['type' => 'datetime'], ['type' => 'datetime', 'columnName' => 'created_at']];
        yield ['arbitraryTypeName', ['type' => 'arbitraryType'], ['type' => 'arbitraryType', 'columnName' => 'arbitrary_type_name']];
    }

    #[DataProvider('attributeConversions')]
    public function testAddsSnakeCaseColumnName(string $attributeName, array $details, array $expected): void
    {
        $metadata = Metadata::create([[
            'uid' => 'admin::permission',
            'singularName' => 'permission',
            'tableName' => 'admin_permissions',
            'attributes' => [$attributeName => $details],
        ]]);

        self::assertEquals([
            'uid' => 'admin::permission',
            'singularName' => 'permission',
            'tableName' => 'admin_permissions',
            'attributes' => [$attributeName => $expected],
            'lifecycles' => [],
            'indexes' => [],
            'foreignKeys' => [],
            'columnToAttribute' => [$expected['columnName'] => $attributeName],
        ], $metadata->get('admin::permission'));
    }

    /** @return iterable<array{string, string, string, bool}> */
    public static function cases(): iterable
    {
        $full = [
            ['string', 'simple.string', false],
            ['relation - One to One', 'relations.oneToOne', false],
            ['relation - One to Many', 'relations.oneToMany', false],
            ['relation - Many to One', 'relations.manyToOne', false],
            ['relation - Inversed One to One', 'relations.inversedOneToOne', false],
            ['relation - Many to Many', 'relations.manyToMany', false],
            ['component - repeatable', 'components.repeatable', true],
            ['component - single', 'components.single', true],
            ['dynamic zone', 'components.dynamicZone', true],
            ['relation - Morph to Many', 'relations.morphToMany', true],
        ];
        foreach ($full as [$label, $path, $aux]) {
            yield "full length: {$label}" => [$path, 'expected-metadata.json', $aux, 0];
            if ($path !== 'relations.morphToMany') {
                yield "shortened: {$label}" => [$path, 'expected-hashed-metadata.json', $aux, 25];
            }
        }
    }

    #[DataProvider('cases')]
    public function testMatchesUpstreamExpectedMetadata(string $path, string $expectedFile, bool $withAux, int $maxLength): void
    {
        $models = self::fixture('models.json');
        [$group, $key] = explode('.', $path);
        $attributes = $models['attributes'][$group][$key];

        $model = $models['baseModel'];
        $model['attributes'] = array_merge($model['attributes'], $attributes);
        $input = $withAux ? [$models['auxComponent'], $model] : [$model];

        $expectedAll = self::fixture($expectedFile);
        $expected = $key === 'dynamicZone' ? $expectedAll['dynamicZone'] : $expectedAll[$group][$key];

        Identifiers::setGlobal(new Identifiers(['maxLength' => $maxLength]));
        $metadata = Metadata::create($input);

        $expectedMap = [];
        foreach ($expected as [$uid, $meta]) {
            $expectedMap[$uid] = $meta;
        }

        self::assertSame(array_keys($expectedMap), $metadata->keys(), 'same uids');
        foreach ($expectedMap as $uid => $meta) {
            self::assertEquals($meta, $metadata->get($uid), "metadata for {$uid}");
        }
    }

    public function testRejectsDuplicateTableNames(): void
    {
        $this->expectExceptionMessage('DB table "t" already exists');
        Metadata::create([
            ['uid' => 'a', 'singularName' => 'a', 'tableName' => 't', 'attributes' => []],
            ['uid' => 'b', 'singularName' => 'b', 'tableName' => 't', 'attributes' => []],
        ]);
    }
}
