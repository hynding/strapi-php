<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests\Controllers\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\ContentTypeBuilder\Controllers\Validation\Component;
use Strapi\ContentTypeBuilder\Tests\StubStrapi;
use Strapi\Utils\Errors\ValidationError;

require_once __DIR__ . '/../../StubStrapi.php';

/** Port of server/src/controllers/validation/__tests__/component.test.ts. */
final class ComponentTest extends TestCase
{
    protected function setUp(): void
    {
        StubStrapi::create();
    }

    /** @return iterable<string, array{string}> */
    public static function methods(): iterable
    {
        yield 'validateComponentInput' => ['validateComponentInput'];
        yield 'validateUpdateComponentInput' => ['validateUpdateComponentInput'];
    }

    /** @return array<string, mixed> */
    private static function input(array $titleAttribute = ['type' => 'string']): array
    {
        return [
            'components' => [],
            'component' => [
                'category' => 'default',
                'displayName' => 'mycompo',
                'icon' => 'calendar',
                'attributes' => ['title' => $titleAttribute],
            ],
        ];
    }

    #[DataProvider('methods')]
    public function testCanValidateARegularComponent(string $method): void
    {
        $input = self::input();

        self::assertSame($input, Component::{$method}($input));
    }

    #[DataProvider('methods')]
    public function testCanUseCustomKeysInAttributes(string $method): void
    {
        $input = self::input(['type' => 'string', 'myCustomKey' => true]);

        self::assertSame($input, Component::{$method}($input));
    }

    #[DataProvider('methods')]
    public function testCannotUseCustomKeysAtRoot(string $method): void
    {
        $this->expectException(ValidationError::class);

        Component::{$method}(['myCustomKey' => true, ...self::input()]);
    }
}
