<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\DocumentService;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Services\DocumentService\Transform\Fields;

/** Port of packages/core/core/src/services/document-service/transform/__tests__/fields.test.ts. */
final class FieldsTest extends TestCase
{
    public function testAddsDocumentIdToArrays(): void
    {
        self::assertSame(['documentId'], Fields::transformFields([]));
        self::assertSame(['id', 'name', 'documentId'], Fields::transformFields(['id', 'name']));
        self::assertSame(['name', 'description', 'documentId'], Fields::transformFields(['name', 'description']));
        self::assertSame(['name', 'documentId'], Fields::transformFields(['name', 'documentId']));
    }

    public function testStringFields(): void
    {
        self::assertSame('*', Fields::transformFields('*'));
        self::assertSame('name,description,documentId', Fields::transformFields('name,description'));
        self::assertSame('documentId', Fields::transformFields(''));
        self::assertSame('name,description,documentId', Fields::transformFields('name,description,documentId'));
        self::assertSame('documentId', Fields::transformFields('documentId'));
    }
}
