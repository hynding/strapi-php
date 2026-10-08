<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests\Controllers\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\ContentTypeBuilder\Controllers\Validation\ContentType;
use Strapi\ContentTypeBuilder\Tests\StubStrapi;
use Strapi\Utils\Errors\ValidationError;

require_once __DIR__ . '/../../StubStrapi.php';

/** Port of server/src/controllers/validation/__tests__/content-type.test.ts. */
final class ContentTypeTest extends TestCase
{
    private const string RESERVED_ATTRIBUTES_MESSAGE = 'Attribute keys cannot be one of id, document_id, created_at, updated_at, published_at, created_by_id, updated_by_id, created_by, updated_by, entry_id, localizations, meta, locale, __component, __contentType, strapi*, _strapi*, __strapi*, status';

    protected function setUp(): void
    {
        StubStrapi::create();
    }

    private static function catchError(\Closure $fn): ValidationError
    {
        try {
            $fn();
        } catch (ValidationError $error) {
            return $error;
        }

        self::fail('Expected a ValidationError');
    }

    public function testValidateKindOnlyAllowsSingleAndCollectionTypes(): void
    {
        $this->expectException(ValidationError::class);
        ContentType::validateKind('wrong');
    }

    public function testValidateKindAllowsSingleTypeAndCollectionType(): void
    {
        self::assertSame('singleType', ContentType::validateKind('singleType'));
        self::assertSame('collectionType', ContentType::validateKind('collectionType'));
    }

    public function testValidateKindAllowsUndefined(): void
    {
        self::assertNull(ContentType::validateKind(null));
    }

    /** @return iterable<string, array{string}> */
    public static function reservedAttributeKeys(): iterable
    {
        yield 'Throws when reserved names are used' => ['entryId'];
        yield 'Uses snake_case to compare reserved name' => ['ENTRY_ID'];
    }

    #[DataProvider('reservedAttributeKeys')]
    public function testPreventsUseOfReservedNamesInAttributes(string $key): void
    {
        $data = [
            'contentType' => [
                'singularName' => 'test',
                'pluralName' => 'tests',
                'displayName' => 'Test',
                'attributes' => [$key => ['type' => 'string', 'default' => '']],
            ],
        ];

        $error = self::catchError(static fn () => ContentType::validateUpdateContentTypeInput($data));

        self::assertSame('ValidationError', $error->name);
        self::assertSame(self::RESERVED_ATTRIBUTES_MESSAGE, $error->getMessage());
        self::assertSame([
            'errors' => [
                [
                    'path' => ['contentType', 'attributes', $key],
                    'message' => self::RESERVED_ATTRIBUTES_MESSAGE,
                    'name' => 'ValidationError',
                    'value' => ['type' => 'string'],
                ],
            ],
        ], $error->details);
    }

    /** @return iterable<string, array{string}> */
    public static function reservedModelNames(): iterable
    {
        yield 'singularName' => ['singularName'];
        yield 'pluralName' => ['pluralName'];
    }

    #[DataProvider('reservedModelNames')]
    public function testPreventsUseOfReservedNamesInModels(string $name): void
    {
        $data = [
            'contentType' => [
                'singularName' => $name === 'singularName' ? 'date-time' : 'not-reserved-single',
                'pluralName' => $name === 'pluralName' ? 'date-time' : 'not-reserved-plural',
                'displayName' => 'Test',
                'attributes' => ['notReserved' => ['type' => 'string', 'default' => '']],
            ],
        ];

        $error = self::catchError(static fn () => ContentType::validateUpdateContentTypeInput($data));

        $message = 'Content Type name cannot be one of boolean, date, date_time, time, upload, document, then, strapi*, _strapi*, __strapi*';
        self::assertSame('ValidationError', $error->name);
        self::assertSame($message, $error->getMessage());
        self::assertCount(1, $error->details['errors']);
        self::assertSame(['contentType', $name], $error->details['errors'][0]['path']);
        self::assertSame($message, $error->details['errors'][0]['message']);
        self::assertSame('ValidationError', $error->details['errors'][0]['name']);
    }

    public function testValidateContentTypeInputCanUseCustomKeys(): void
    {
        $input = [
            'contentType' => [
                'displayName' => 'test',
                'singularName' => 'test',
                'pluralName' => 'tests',
                'attributes' => [
                    'views' => ['type' => 'integer', 'myCustomKey' => 10000],
                    'title' => ['type' => 'string', 'myCustomKey' => true],
                ],
            ],
        ];

        $data = ContentType::validateContentTypeInput($input);

        self::assertSame($input['contentType']['attributes'], $data['contentType']['attributes']);
    }

    public function testValidateUpdateContentTypeInputDeletesEmptyDefaults(): void
    {
        $data = [
            'contentType' => [
                'displayName' => 'test',
                'singularName' => 'test',
                'pluralName' => 'tests',
                'attributes' => ['slug' => ['type' => 'string', 'default' => '']],
            ],
            'components' => [
                [
                    'uid' => 'edit.edit',
                    'displayName' => 'test',
                    'icon' => 'calendar',
                    'category' => 'test',
                    'attributes' => ['title' => ['type' => 'string', 'default' => '']],
                ],
                [
                    'tmpUID' => 'random.random',
                    'displayName' => 'test2',
                    'icon' => 'calendar',
                    'category' => 'test',
                    'attributes' => ['title' => ['type' => 'string', 'default' => '']],
                ],
            ],
        ];

        $cleaned = ContentType::validateUpdateContentTypeInput($data);

        self::assertArrayNotHasKey('default', $cleaned['contentType']['attributes']['slug']);
        self::assertArrayNotHasKey('default', $cleaned['components'][0]['attributes']['title']);
        self::assertSame('', $cleaned['components'][1]['attributes']['title']['default']);
    }

    public function testDeletedUidTargetFieldsAreRemovedFromInputData(): void
    {
        $data = [
            'contentType' => [
                'displayName' => 'test',
                'singularName' => 'test',
                'pluralName' => 'tests',
                'attributes' => ['slug' => ['type' => 'uid', 'targetField' => 'deletedField']],
            ],
        ];

        $cleaned = ContentType::validateUpdateContentTypeInput($data);

        self::assertArrayNotHasKey('targetField', $cleaned['contentType']['attributes']['slug']);
    }

    public function testValidateUpdateContentTypeInputCanUseCustomKeys(): void
    {
        $input = [
            'contentType' => [
                'displayName' => 'test',
                'singularName' => 'test',
                'pluralName' => 'tests',
                'attributes' => [
                    'views' => ['type' => 'integer', 'myCustomKey' => 10000],
                    'title' => ['type' => 'string', 'myCustomKey' => true],
                ],
            ],
        ];

        $data = ContentType::validateUpdateContentTypeInput($input);

        self::assertSame($input['contentType']['attributes'], $data['contentType']['attributes']);
    }
}
