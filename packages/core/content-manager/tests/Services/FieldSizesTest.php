<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Services;

require_once __DIR__ . '/../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Services\FieldSizes;
use Strapi\ContentManager\Tests\StubStrapi;
use Strapi\Utils\Errors\ApplicationError;

/** Port of server/src/services/__tests__/field-sizes.test.ts. */
final class FieldSizesTest extends TestCase
{
    private function service(): FieldSizes
    {
        $strapi = StubStrapi::create();
        // Mock container.get('custom-fields').getAll()
        $strapi->set('custom-fields', new class () {
            /** @return array<string, array<string, mixed>> */
            public function getAll(): array
            {
                return [
                    'plugin::mycustomfields.color' => ['name' => 'color', 'plugin' => 'mycustomfields', 'type' => 'string'],
                    'plugin::mycustomfields.smallColor' => [
                        'name' => 'smallColor',
                        'plugin' => 'mycustomfields',
                        'type' => 'string',
                        'inputSize' => ['default' => 4, 'isResizable' => false],
                    ],
                ];
            }
        });

        return new FieldSizes($strapi);
    }

    protected function setUp(): void
    {
        FieldSizes::reset();
    }

    protected function tearDown(): void
    {
        FieldSizes::reset();
    }

    public function testShouldReturnTheCorrectFieldSizes(): void
    {
        foreach ($this->service()->getAllFieldSizes() as $fieldSize) {
            self::assertIsBool($fieldSize['isResizable']);
            self::assertContains($fieldSize['default'], [4, 6, 8, 12]);
        }
    }

    public function testShouldReturnTheCorrectFieldSizeForAGivenType(): void
    {
        $fieldSize = $this->service()->getFieldSize('string');
        self::assertTrue($fieldSize['isResizable']);
        self::assertSame(6, $fieldSize['default']);
    }

    public function testShouldThrowAnErrorIfTheTypeIsNotFound(): void
    {
        $this->expectException(ApplicationError::class);
        $this->expectExceptionMessage('Could not find field size for type not-found');
        $this->service()->getFieldSize('not-found');
    }

    public function testShouldThrowAnErrorIfTheTypeIsNotProvided(): void
    {
        $this->expectException(ApplicationError::class);
        $this->expectExceptionMessage('The type is required');
        $this->service()->getFieldSize();
    }

    public function testShouldSetTheCustomFieldsInputSizes(): void
    {
        $service = $this->service();
        $service->setCustomFieldInputSizes();
        $fieldSizes = $service->getAllFieldSizes();

        self::assertArrayNotHasKey('plugin::mycustomfields.color', $fieldSizes);
        self::assertSame(4, $fieldSizes['plugin::mycustomfields.smallColor']['default']);
        self::assertFalse($fieldSizes['plugin::mycustomfields.smallColor']['isResizable']);
    }
}
