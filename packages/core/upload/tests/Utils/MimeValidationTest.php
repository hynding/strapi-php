<?php

declare(strict_types=1);

namespace Strapi\Upload\Tests\Utils;

use Strapi\Tests\AppTestCase;
use Strapi\Upload\Utils\MimeValidation;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of server/src/utils/__tests__/mime-validation.test.ts. Upstream mocks `file-type`; here
 * detection runs on real fixtures ({@see \Strapi\Upload\Utils\FileType}).
 */
final class MimeValidationTest extends AppTestCase
{
    private static function fixture(string $name): string
    {
        return __DIR__ . '/fixtures/' . $name;
    }

    /** @return \ArrayObject<string, mixed> */
    private static function file(string $fixture, string $filename, string $mimetype): \ArrayObject
    {
        return new \ArrayObject(['filepath' => self::fixture($fixture), 'originalFilename' => $filename, 'mimetype' => $mimetype, 'size' => 1]);
    }

    protected function tearDown(): void
    {
        self::strapi()->config()->set('plugin::upload.security', []);
    }

    public function testDetectMimeTypeThrowsWhenFileReadingFails(): void
    {
        $this->expectExceptionMessageMatches('/^Failed to read file:/');
        MimeValidation::detectMimeType(['filepath' => '/nonexistent/file']);
    }

    public function testDetectMimeTypeReturnsNullWhenDetectionHasNoResult(): void
    {
        self::assertNull(MimeValidation::detectMimeType(['filepath' => self::fixture('rec.txt')]));
        self::assertSame('image/jpeg', MimeValidation::detectMimeType(['filepath' => self::fixture('rec.jpg')]));
        self::assertNull(MimeValidation::detectMimeType([]));
    }

    public function testIsMimeTypeAllowed(): void
    {
        // empty MIME type
        self::assertFalse(MimeValidation::isMimeTypeAllowed('', []));
        // no restrictions
        self::assertTrue(MimeValidation::isMimeTypeAllowed('image/jpeg', []));
        // explicit empty allow list: nothing allowed
        self::assertFalse(MimeValidation::isMimeTypeAllowed('image/jpeg', ['allowedTypes' => []]));
        // denied list
        self::assertFalse(MimeValidation::isMimeTypeAllowed('application/x-sh', ['deniedTypes' => ['application/x-sh']]));
        // allowed list only
        self::assertTrue(MimeValidation::isMimeTypeAllowed('image/png', ['allowedTypes' => ['image/*']]));
        self::assertFalse(MimeValidation::isMimeTypeAllowed('application/pdf', ['allowedTypes' => ['image/*']]));
        // exact match
        self::assertTrue(MimeValidation::isMimeTypeAllowed('application/pdf', ['allowedTypes' => ['application/pdf']]));
        // deny takes precedence
        self::assertFalse(MimeValidation::isMimeTypeAllowed('image/svg+xml', ['allowedTypes' => ['image/*'], 'deniedTypes' => ['image/svg+xml']]));
        // case insensitive
        self::assertTrue(MimeValidation::isMimeTypeAllowed('IMAGE/JPEG', ['allowedTypes' => ['image/jpeg']]));
    }

    public function testValidFileWhenNoSecurityConfigIsProvided(): void
    {
        $result = MimeValidation::validateFile(self::file('rec.jpg', 'rec.jpg', 'image/jpeg'), [], self::strapi());

        self::assertTrue($result['isValid']);
        self::assertSame('image/jpeg', $result['detectedMime']);
    }

    public function testNoConfigUsesTheDetectedTypeWhenDeclaredIsOctetStream(): void
    {
        $result = MimeValidation::validateFile(self::file('rec.pdf', 'document.pdf', 'application/octet-stream'), [], self::strapi());

        self::assertSame('application/pdf', $result['detectedMime']);
    }

    public function testNoConfigAndNoDetectionUsesTheExtension(): void
    {
        $result = MimeValidation::validateFile(self::file('rec.txt', 'notes.txt', 'application/octet-stream'), [], self::strapi());

        self::assertTrue($result['isValid']);
        self::assertSame('text/plain', $result['detectedMime']);
    }

    public function testStoresVideoQuicktimeForAMovDeclaredAsOctetStream(): void
    {
        $result = MimeValidation::validateFile(self::file('rec.txt', 'clip.mov', 'application/octet-stream'), ['allowedTypes' => ['video/*']], self::strapi());

        self::assertTrue($result['isValid']);
        self::assertSame('video/quicktime', $result['detectedMime']);
    }

    public function testRejectsDisallowedMimeType(): void
    {
        $result = MimeValidation::validateFile(self::file('rec.jpg', 'rec.jpg', 'image/jpeg'), ['allowedTypes' => ['application/pdf']], self::strapi());

        self::assertFalse($result['isValid']);
        self::assertSame('MIME_TYPE_NOT_ALLOWED', $result['error']['code'] ?? null);
    }

    public function testRejectsContentDetectedOutsideTheAllowListWhateverTheExtension(): void
    {
        $result = MimeValidation::validateFile(self::file('rec.jpg', 'fake.pdf', 'application/pdf'), ['allowedTypes' => ['application/pdf']], self::strapi());

        self::assertFalse($result['isValid']);
        self::assertSame('MIME type is not allowed', $result['error']['message'] ?? null);
    }

    public function testAllowsSvgDetectedAsXmlWhenImagesAreAllowed(): void
    {
        $result = MimeValidation::validateFile(self::file('strapi.svg', 'strapi.svg', 'image/svg+xml'), ['allowedTypes' => ['image/*']], self::strapi());

        self::assertTrue($result['isValid']);
        self::assertSame('image/svg+xml', $result['detectedMime']);
    }

    public function testRejectsEveryFileWhenAllowedTypesIsAnExplicitEmptyArray(): void
    {
        foreach (['rec.jpg', 'rec.txt'] as $fixture) {
            $result = MimeValidation::validateFile(self::file($fixture, $fixture, 'application/octet-stream'), ['allowedTypes' => []], self::strapi());
            self::assertFalse($result['isValid']);
        }
    }

    public function testExtractsFileInfoFromVariousFileObjectFormats(): void
    {
        self::assertSame(['fileName' => 'a.png', 'declaredMimeType' => 'image/png'], MimeValidation::extractFileInfo(['originalFilename' => 'a.png', 'mimetype' => 'image/png']));
        self::assertSame(['fileName' => 'b.png', 'declaredMimeType' => 'image/png'], MimeValidation::extractFileInfo(['name' => 'b.png', 'type' => 'image/png']));
        self::assertSame(['fileName' => 'unknown', 'declaredMimeType' => ''], MimeValidation::extractFileInfo([]));
    }

    public function testValidateFilesHandlesASingleFileAnArrayAndEmptyInput(): void
    {
        self::assertCount(1, MimeValidation::validateFiles(self::file('rec.jpg', 'rec.jpg', 'image/jpeg'), self::strapi()));
        self::assertCount(2, MimeValidation::validateFiles([self::file('rec.jpg', 'rec.jpg', 'image/jpeg'), self::file('rec.pdf', 'rec.pdf', 'application/pdf')], self::strapi()));
        self::assertSame([], MimeValidation::validateFiles([], self::strapi()));
    }

    public function testValidateFilesThrowsOnAnInvalidConfiguration(): void
    {
        foreach ([
            [['allowedTypes' => 'image/*'], 'Invalid configuration: allowedTypes must be an array of strings.'],
            [['allowedTypes' => ['image/*', 1]], 'Invalid configuration: allowedTypes must be an array of strings.'],
            [['deniedTypes' => 'image/*'], 'Invalid configuration: deniedTypes must be an array of strings.'],
            [['deniedTypes' => [true]], 'Invalid configuration: deniedTypes must be an array of strings.'],
        ] as [$security, $message]) {
            self::strapi()->config()->set('plugin::upload.security', $security);
            try {
                MimeValidation::validateFiles(self::file('rec.jpg', 'rec.jpg', 'image/jpeg'), self::strapi());
                self::fail('expected an error');
            } catch (ApplicationError $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
    }

    public function testEnforceUploadSecurityEnrichesValidFilesAndIndexesErrors(): void
    {
        self::strapi()->config()->set('plugin::upload.security', ['allowedTypes' => ['application/pdf']]);
        $files = [self::file('rec.jpg', 'rec.jpg', 'image/jpeg'), self::file('rec.pdf', 'document.pdf', 'application/octet-stream')];

        $result = MimeValidation::enforceUploadSecurity($files, self::strapi());

        self::assertCount(1, $result['validFiles']);
        self::assertSame('application/pdf', $result['validFiles'][0]['detectedMimeType']);
        self::assertSame(['document.pdf'], $result['validFileNames']);
        self::assertSame(0, $result['errors'][0]['originalIndex']);
    }

    public function testPrepareUploadRequestFiltersFileInfoOfRejectedFiles(): void
    {
        self::strapi()->config()->set('plugin::upload.security', ['allowedTypes' => ['application/pdf']]);
        $files = [self::file('rec.jpg', 'rec.jpg', 'image/jpeg'), self::file('rec.pdf', 'rec.pdf', 'application/pdf')];

        $result = MimeValidation::prepareUploadRequest($files, ['fileInfo' => ['{"name":"a"}', '{"name":"b"}']], self::strapi());

        self::assertSame(['fileInfo' => ['name' => 'b']], $result['filteredBody']);
        self::assertSame([['name' => 'rec.jpg', 'message' => "File type 'image/jpeg' is not allowed"]], $result['errors']);
    }
}
