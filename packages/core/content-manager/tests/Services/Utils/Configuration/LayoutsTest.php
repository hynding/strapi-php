<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Services\Utils\Configuration;

require_once __DIR__ . '/../../../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Services\Utils\Configuration\Layouts;
use Strapi\ContentManager\Tests\StubStrapi;
use Strapi\Core\Strapi;

/** Port of server/src/services/utils/configuration/__tests__/layouts.test.ts. */
final class LayoutsTest extends TestCase
{
    private Strapi $strapi;

    protected function setUp(): void
    {
        $this->strapi = StubStrapi::create();
        StubStrapi::setService($this->strapi, 'field-sizes', new class () {
            /** @return array{default: int, isResizable: bool}|null */
            public function getFieldSize(?string $type): ?array
            {
                if ($type === 'integer' || $type === 'string') {
                    return ['default' => 6, 'isResizable' => true];
                }
                if ($type === 'json' || $type === 'customField') {
                    return ['default' => 12, 'isResizable' => false];
                }

                return null;
            }

            public function hasFieldSize(?string $type): bool
            {
                return $type === 'customField';
            }
        });
    }

    /**
     * @param array<string, array<string, mixed>> $attributes
     * @return array<string, mixed>
     */
    private static function createMockSchema(array $attributes = []): array
    {
        return [
            'attributes' => [
                'id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'nodes' => ['type' => 'json'],
                ...$attributes,
            ],
            'config' => ['attributes' => ['title' => [], 'nodes' => []]],
        ];
    }

    public function testShouldCreateDefaultLayoutsWithValidFieldsIfNoConfigurationIsProvided(): void
    {
        $layout = Layouts::syncLayouts($this->strapi, ['layouts' => []], self::createMockSchema());

        self::assertSame(['id', 'title'], $layout['list']);
        self::assertSame([[['name' => 'title', 'size' => 6]], [['name' => 'nodes', 'size' => 12]]], $layout['edit']);
    }

    public function testShouldAppendNewFieldsAtTheEndOfTheLayouts(): void
    {
        $configuration = [
            'layouts' => [
                'list' => ['id', 'title'],
                'edit' => [[['name' => 'title', 'size' => 6]], [['name' => 'nodes', 'size' => 12]]],
            ],
            'metadatas' => ['id' => [], 'title' => [], 'nodes' => []],
        ];

        $layout = Layouts::syncLayouts($this->strapi, $configuration, self::createMockSchema(['description' => ['type' => 'string']]));

        self::assertSame(['id', 'title', 'description'], $layout['list']);
        self::assertSame([
            [['name' => 'title', 'size' => 6]],
            [['name' => 'nodes', 'size' => 12]],
            [['name' => 'description', 'size' => 6]],
        ], $layout['edit']);
    }

    public function testShouldUseTheCustomFieldSizeIfTheFieldIsACustomFieldWithCustomSize(): void
    {
        $configuration = [
            'layouts' => [
                'list' => ['id', 'title'],
                'edit' => [[['name' => 'title', 'size' => 6]], [['name' => 'nodes', 'size' => 12]]],
            ],
            'metadatas' => ['id' => [], 'title' => [], 'nodes' => []],
        ];

        $layout = Layouts::syncLayouts($this->strapi, $configuration, self::createMockSchema(['color' => ['type' => 'string', 'customField' => 'customField']]));

        self::assertSame(['id', 'title', 'color'], $layout['list']);
        self::assertSame([
            [['name' => 'title', 'size' => 6]],
            [['name' => 'nodes', 'size' => 12]],
            [['name' => 'color', 'size' => 12]],
        ], $layout['edit']);
    }
}
