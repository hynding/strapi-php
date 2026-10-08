<?php

declare(strict_types=1);

namespace Strapi\Upload\Tests\Controllers\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use Strapi\Tests\AppTestCase;
use Strapi\Upload\Controllers\Validation\Admin\Upload as AdminUpload;
use Strapi\Upload\Controllers\Validation\ContentApi\Upload as ContentApiUpload;
use Strapi\Utils\Errors\ValidationError;

/**
 * Port of server/src/controllers/validation/__tests__/upload-focal-point.test.ts: the focal point
 * is a percentage of the image, so 0–100 is the whole valid range.
 */
final class UploadFocalPointTest extends AppTestCase
{
    /** @return list<array{string}> */
    public static function apis(): array
    {
        return [['admin'], ['content-api']];
    }

    private static function validate(string $api, mixed $data): mixed
    {
        return $api === 'admin' ? AdminUpload::validateUploadBody(self::strapi(), $data) : ContentApiUpload::validateUploadBody($data);
    }

    private static function assertRejected(string $api, mixed $focalPoint): void
    {
        try {
            self::validate($api, ['fileInfo' => ['focalPoint' => $focalPoint]]);
        } catch (ValidationError) {
            self::assertTrue(true);

            return;
        }
        self::fail('Expected a validation error for ' . json_encode($focalPoint));
    }

    #[DataProvider('apis')]
    public function testAcceptsBothEndsOfTheRange(string $api): void
    {
        self::assertNotEmpty(self::validate($api, ['fileInfo' => ['focalPoint' => ['x' => 0, 'y' => 0]]]));
        self::assertNotEmpty(self::validate($api, ['fileInfo' => ['focalPoint' => ['x' => 100, 'y' => 100]]]));
    }

    #[DataProvider('apis')]
    public function testRejectsAValuePastTheFarEdge(string $api): void
    {
        self::assertRejected($api, ['x' => 50, 'y' => 101]);
        self::assertRejected($api, ['x' => 101, 'y' => 50]);
    }

    #[DataProvider('apis')]
    public function testRejectsANegativeValue(string $api): void
    {
        self::assertRejected($api, ['x' => -1, 'y' => 50]);
        self::assertRejected($api, ['x' => 50, 'y' => -1]);
    }

    #[DataProvider('apis')]
    public function testRequiresBothAxesOnceAFocalPointIsGiven(string $api): void
    {
        self::assertRejected($api, ['x' => 50]);
        self::assertRejected($api, ['y' => 50]);
    }

    #[DataProvider('apis')]
    public function testAcceptsNoFocalPointAtAll(string $api): void
    {
        self::assertNotEmpty(self::validate($api, ['fileInfo' => ['focalPoint' => null]]));
        self::assertNotEmpty(self::validate($api, ['fileInfo' => []]));
    }
}
