<?php

declare(strict_types=1);

namespace Strapi\Upload\Tests\Controllers\Validation\Admin;

use PHPUnit\Framework\TestCase;
use Strapi\Upload\Constants;
use Strapi\Upload\Controllers\Validation\Admin\AiMetadata;
use Strapi\Utils\Errors\ValidationError;

/** Port of server/src/controllers/validation/admin/__tests__/ai-metadata.test.ts. */
final class AiMetadataTest extends TestCase
{
    /** @return list<int> */
    private static function idsOfLength(int $length): array
    {
        return range(1, $length);
    }

    public function testAcceptsASelectionWithinTheLimit(): void
    {
        $fileIds = self::idsOfLength(Constants::AI_METADATA_MAX_FILES);

        self::assertSame(['fileIds' => $fileIds], AiMetadata::validateGenerateAIMetadataBody(['fileIds' => $fileIds]));
    }

    public function testRejectsAnEmptySelection(): void
    {
        $this->expectException(ValidationError::class);
        AiMetadata::validateGenerateAIMetadataBody(['fileIds' => []]);
    }

    public function testRejectsASelectionAboveTheLimit(): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('You can generate metadata for up to ' . Constants::AI_METADATA_MAX_FILES . ' assets at a time');
        AiMetadata::validateGenerateAIMetadataBody(['fileIds' => self::idsOfLength(Constants::AI_METADATA_MAX_FILES + 1)]);
    }

    public function testRejectsUnknownKeys(): void
    {
        $this->expectException(ValidationError::class);
        AiMetadata::validateGenerateAIMetadataBody(['fileIds' => [1], 'somethingElse' => true]);
    }
}
